<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\FunnelSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicRegistrationController extends Controller
{
    private function page(string $slug, bool $lock = false): object
    {
        $query = DB::table('landing_pages')->where('slug', $slug)->where('status', 'published');
        if ($lock) {
            $query->lockForUpdate();
        }
        $page = $query->first();
        abort_unless($page, 404);
        $vacancyQuery = Vacancy::whereKey($page->vacancy_id)->where('status', 'published');
        if ($lock) {
            $vacancyQuery->lockForUpdate();
        }
        $vacancy = $vacancyQuery->with(['jobOrder.country'])->first();
        abort_unless($vacancy, 404);
        abort_if($vacancy->public_enabled && (($vacancy->valid_through && $vacancy->valid_through->isPast()) || ($vacancy->publish_at && $vacancy->publish_at->isFuture())), 404);
        if ($page->campaign_id) {
            $campaignQuery = DB::table('campaigns')->where('id', $page->campaign_id);
            if ($lock) {
                $campaignQuery->lockForUpdate();
            }
            $campaign = $campaignQuery->first();
            $today = now('Asia/Jakarta')->toDateString();
            abort_unless($campaign && $campaign->status === 'active' && (! $campaign->vacancy_id || (int) $campaign->vacancy_id === (int) $vacancy->id) && (! $campaign->start_date || $campaign->start_date <= $today) && (! $campaign->end_date || $campaign->end_date >= $today), 404);
        }
        $page->vacancy = $vacancy;

        return $page;
    }

    private function funnel(object $page): object
    {
        return DB::transaction(function () use ($page): object {
            DB::table('landing_pages')->where('id', $page->id)->lockForUpdate()->first();
            $slug = 'legacy-'.$page->id.'-'.substr($page->slug, 0, 220);
            $existing = DB::table('application_funnels')->where('slug', $slug)->first();
            if ($existing) {
                return $existing;
            }
            $schema = FunnelSchema::defaults();
            $schema['blocks'][0]['title'] = $page->title;
            $schema['blocks'][0]['text'] = $page->content ?? '';
            $id = DB::table('application_funnels')->insertGetId(['name' => $page->title, 'slug' => $slug, 'vacancy_id' => $page->vacancy_id, 'status' => 'published', 'draft_schema' => json_encode($schema), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $version = DB::table('funnel_versions')->insertGetId(['funnel_id' => $id, 'vacancy_id' => $page->vacancy_id, 'version' => 1, 'schema_snapshot' => json_encode($schema), 'published_at' => now(), 'change_summary' => 'Pendaftaran lama menggunakan alur lead terversi.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('application_funnels')->where('id', $id)->update(['active_version_id' => $version]);

            return DB::table('application_funnels')->find($id);
        });
    }

    public function show(Request $request, string $slug): View
    {
        $attribution = $request->validate(array_fill_keys(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'referral_code'], ['nullable', 'string', 'max:255']));
        $page = $this->page($slug);
        if ($page->funnel_id) {
            $funnel = DB::table('application_funnels')->where('id', $page->funnel_id)->where('status', 'published')->first() ?? abort(404);
            $version = DB::table('funnel_versions')->where('id', $funnel->active_version_id)->where('funnel_id', $funnel->id)->where('vacancy_id', $page->vacancy_id)->first() ?? abort(404);
            $view = app(FunnelPublicController::class)->show($request, $funnel->slug);
            $request->session()->put('funnel_attribution.'.$funnel->id, $request->session()->get('funnel_attribution.'.$funnel->id, []) + ['campaign_id' => $page->campaign_id, 'landing_page_id' => $page->id]);

            return $view;
        }
        $key = 'registration_token.'.$page->id;
        if (! $request->session()->has($key)) {
            $request->session()->put($key, (string) Str::uuid());
        }

        return view('recruitment.registration', ['page' => $page, 'token' => $request->session()->get($key), 'attribution' => $attribution]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $page = $this->page($slug);
        $data = $request->validate(['full_name' => ['required', 'string', 'max:255'], 'whatsapp' => ['required', 'string', 'regex:/^\+?[0-9][0-9 ()-]{7,28}$/', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'agreement' => ['accepted'], 'registration_token' => ['required', 'string', 'max:36'], 'website' => ['prohibited'], 'utm_source' => ['nullable', 'string', 'max:255'], 'utm_medium' => ['nullable', 'string', 'max:255'], 'utm_campaign' => ['nullable', 'string', 'max:255'], 'utm_content' => ['nullable', 'string', 'max:255'], 'referral_code' => ['nullable', 'string', 'max:255']], ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute terlalu panjang.', 'email' => 'Alamat email tidak valid.', 'regex' => 'Nomor WhatsApp tidak valid.', 'accepted' => 'Setujui pengiriman data untuk proses perekrutan.'], ['full_name' => 'Nama lengkap', 'whatsapp' => 'Nomor WhatsApp', 'registration_token' => 'Formulir pendaftaran']);
        $tokenKey = 'registration_token.'.$page->id;
        $token = $request->session()->get($tokenKey);
        if (! is_string($token) || ! hash_equals($token, $data['registration_token'])) {
            throw ValidationException::withMessages(['registration_token' => 'Formulir sudah dikirim atau kedaluwarsa. Muat ulang halaman untuk mendaftar.']);
        }
        $funnel = $this->funnel($page);
        $request->session()->put('funnel_token.'.$funnel->id, ['token' => $token, 'version' => $funnel->active_version_id]);
        $request->session()->put('funnel_attribution.'.$funnel->id, $request->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'referral_code']) + ['campaign_id' => $page->campaign_id, 'landing_page_id' => $page->id, 'landing_url' => route('registration.show', $slug)]);
        $request->merge(['answers' => ['name' => $data['full_name'], 'whatsapp' => $data['whatsapp'], 'email' => $data['email'] ?? null], 'idempotency_key' => $token, 'version_id' => $funnel->active_version_id, 'consent' => $data['agreement']]);
        app(FunnelPublicController::class)->store($request, $funnel->slug);
        $request->session()->forget($tokenKey);

        return to_route('registration.show', $slug)->with('registration_success', $page->id);
    }
}
