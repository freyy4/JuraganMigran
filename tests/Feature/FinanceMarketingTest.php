<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceMarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Storage::fake('documents');
        $this->travelTo(Carbon::parse('2026-10-03 12:00', 'Asia/Jakarta')->utc());
    }

    private function invoice(): array
    {
        $application = Application::factory()->create();
        $company = $application->vacancy->jobOrder->company;
        $this->post(route('agreements.store'), ['company_id' => $company->id, 'agreement_number' => 'AGR-1', 'status' => 'active'])->assertSessionHasNoErrors();
        $agreement = DB::table('commercial_agreements')->first();
        $this->post(route('milestones.store'), ['agreement_id' => $agreement->id, 'name' => 'Tahap awal', 'trigger_type' => 'manual', 'amount' => '100.10', 'currency' => 'IDR', 'sequence' => 0])->assertSessionHasNoErrors();
        $milestone = DB::table('agreement_milestones')->first();
        $this->post(route('receivables.store'), ['application_id' => $application->id, 'agreement_milestone_id' => $milestone->id, 'amount' => '100.10', 'currency' => 'IDR', 'invoice_number' => 'INV-1'])->assertSessionHasNoErrors();

        return [$application, DB::table('sponsor_receivables')->first(), $agreement, $milestone];
    }

    private function payment(string $amount, string $reference): array
    {
        return ['amount' => $amount, 'paid_at' => '2026-10-03T10:00', 'reference_number' => $reference];
    }

    public function test_invoice_installments_balance_duplicate_and_private_evidence(): void
    {
        [$application,$invoice,$agreement,$milestone] = $this->invoice();
        $data = $this->payment('40.05', 'PAY-1') + ['receivable_id' => $invoice->id, 'evidence' => UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf')];
        $this->post(route('sponsor-payments.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sponsor_receivables', ['id' => $invoice->id, 'status' => 'partial']);
        $payment = DB::table('sponsor_payments')->first();
        $this->assertSame('2026-10-03 03:00:00', $payment->paid_at);
        $this->get(route('finance-file.sponsor', $payment->id))->assertOk()->assertDownload();
        $files = Storage::disk('documents')->allFiles();
        $this->post(route('sponsor-payments.store'), array_replace($data, ['evidence' => UploadedFile::fake()->create('duplicate.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('reference_number');
        $this->assertSame($files, Storage::disk('documents')->allFiles());
        $this->post(route('sponsor-payments.store'), $this->payment('60.06', 'PAY-2') + ['receivable_id' => $invoice->id])->assertSessionHasErrors('amount');
        $this->post(route('sponsor-payments.store'), $this->payment('60.05', 'PAY-2') + ['receivable_id' => $invoice->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sponsor_receivables', ['id' => $invoice->id, 'status' => 'paid']);
        $this->get(route('settlements.index'))->assertOk()->assertSee('Selesai')->assertSee('0.00');
        $this->put(route('receivables.update', $invoice->id), ['application_id' => $application->id, 'agreement_milestone_id' => $milestone->id, 'amount' => '101', 'currency' => 'IDR', 'invoice_number' => 'INV-1'])->assertSessionHasErrors('amount');
    }

    public function test_obligation_and_benefit_exact_balances_and_disbursements(): void
    {
        $application = Application::factory()->create();
        $this->post(route('obligations.store'), ['application_id' => $application->id, 'component' => 'Komponen', 'basis' => 'Dasar tertulis', 'agreement_reference' => 'AGR-REF', 'amount' => '0.30'])->assertSessionHasNoErrors();
        $obligation = DB::table('financial_obligations')->first();
        $this->post(route('obligations.pay', $obligation->id), $this->payment('0.10', 'O-1'))->assertSessionHasNoErrors();
        $this->post(route('obligations.pay', $obligation->id), $this->payment('0.20', 'O-2'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('financial_obligations', ['id' => $obligation->id, 'balance' => '0.00', 'status' => 'settled']);
        $this->post(route('benefits.store'), ['application_id' => $application->id, 'target_amount' => '50', 'currency' => 'USD', 'basis' => 'Hak tercatat'])->assertSessionHasNoErrors();
        $benefit = DB::table('candidate_benefit_plans')->first();
        $this->post(route('benefits.pay', $benefit->id), $this->payment('51', 'B-1') + ['purpose' => 'Biaya tercatat', 'recipient' => 'Penerima'])->assertSessionHasErrors('amount');
        $this->post(route('benefits.pay', $benefit->id), $this->payment('25', 'B-1') + ['purpose' => 'Biaya tercatat', 'recipient' => 'Penerima'])->assertSessionHasNoErrors();
        $this->post(route('benefits.pay', $benefit->id), $this->payment('25', 'B-1') + ['purpose' => 'Tujuan berbeda', 'recipient' => 'Penerima'])->assertSessionHasErrors('reference_number');
        $this->post(route('benefits.pay', $benefit->id), $this->payment('25', 'B-2') + ['purpose' => 'Biaya tercatat', 'recipient' => 'Penerima'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('candidate_benefit_plans', ['id' => $benefit->id, 'status' => 'completed']);
        $this->get(route('benefits.index'))->assertOk()->assertSee('Biaya tercatat');
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_invalid_partner_currency_percentage_and_dates_are_rejected(): void
    {
        [$application,$invoice,$agreement,$milestone] = $this->invoice();
        $this->post(route('receivables.store'), ['application_id' => Application::factory()->create()->id, 'agreement_milestone_id' => $milestone->id, 'amount' => '20', 'currency' => 'IDR'])->assertSessionHasErrors('agreement_milestone_id');
        $this->post(route('receivables.store'), ['application_id' => $application->id, 'agreement_milestone_id' => $milestone->id, 'amount' => '20', 'currency' => 'USD'])->assertSessionHasErrors('agreement_milestone_id');
        $this->post(route('milestones.store'), ['agreement_id' => $agreement->id, 'name' => 'Persentase', 'trigger_type' => 'manual', 'percentage' => '101', 'currency' => 'IDR', 'sequence' => 1])->assertSessionHasErrors('percentage');
        $this->post(route('milestones.store'), ['agreement_id' => $agreement->id, 'name' => 'Kosong', 'trigger_type' => 'manual', 'currency' => 'IDR', 'sequence' => 1])->assertSessionHasErrors('amount');
        $this->post(route('sponsor-payments.store'), array_replace($this->payment('10', 'FUTURE'), ['receivable_id' => $invoice->id, 'paid_at' => '2026-10-04T10:00']))->assertSessionHasErrors('paid_at');
    }

    public function test_all_finance_pages_forms_and_guest_restrictions(): void
    {
        foreach (['agreements', 'milestones', 'receivables', 'sponsor-payments', 'benefits', 'obligations', 'settlements'] as $module) {
            $this->get(route($module.'.index'))->assertOk()->assertSee('Bagian Keuangan');
            if ($module !== 'settlements') {
                $this->get(route($module.'.create'))->assertOk();
            }
        }
        auth()->logout();
        foreach (['agreements', 'milestones', 'receivables', 'sponsor-payments', 'benefits', 'obligations', 'settlements'] as $module) {
            $this->get(route($module.'.index'))->assertRedirect(route('login'));
        }
        $this->post(route('sponsor-payments.store'), [])->assertRedirect(route('login'));
        $this->get(route('finance-file.sponsor', 1))->assertRedirect(route('login'));
    }

    public function test_sources_referrals_and_analytics_use_distinct_candidates_and_filters(): void
    {
        $candidate = Candidate::factory()->create();
        $data = ['candidate_id' => $candidate->id, 'source' => 'Mitra', 'medium' => 'Rujukan', 'referral_code' => 'REF-1'];
        $this->post(route('referrals.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('lead-sources.store'), array_replace($data, ['source' => 'Instagram', 'referral_code' => null]))->assertSessionHasNoErrors();
        $this->get(route('referrals.index'))->assertOk()->assertSee('REF-1')->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('marketing-analytics.index'))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['records'] === 2 && $stats['candidates'] === 1);
        $this->get(route('lead-sources.index', ['search' => $candidate->candidate_code, 'date_from' => '2026-10-03', 'date_to' => '2026-10-03']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->post(route('referrals.store'), array_replace($data, ['referral_code' => null]))->assertSessionHasErrors('referral_code');
        $this->get(route('marketing-analytics.index', ['date_from' => '2026-10-04', 'date_to' => '2026-10-03']))->assertSessionHasErrors('date_to');
    }

    public function test_public_referral_and_utm_are_saved_with_server_selected_page(): void
    {
        auth()->logout();
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $id = DB::table('landing_pages')->insertGetId(['vacancy_id' => $vacancy->id, 'title' => 'Rujukan', 'slug' => 'rujukan', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('registration.show', ['slug' => 'rujukan', 'referral_code' => 'REF-PUBLIC', 'utm_source' => 'partner']))->assertOk()->assertSee('REF-PUBLIC');
        $this->post(route('registration.store', 'rujukan'), ['full_name' => 'Nama Baru', 'whatsapp' => '081234567890', 'agreement' => 1, 'registration_token' => session('registration_token.'.$id), 'referral_code' => 'REF-PUBLIC', 'utm_source' => 'partner'])->assertSessionHasNoErrors();
        $submission = DB::table('lead_submissions')->first();
        $attribution = json_decode($submission->attribution, true);
        $this->assertSame($id, $attribution['landing_page_id']);
        $this->assertSame('REF-PUBLIC', $attribution['referral_code']);
        $this->assertSame('partner', $attribution['utm_source']);
    }
}
