<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\JobOrder;
use App\Models\User;
use App\Support\MoneyInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_and_normalization_preserve_large_amounts_and_fractional_digits(): void
    {
        $this->assertSame('9.999.999.999.999.999,99', MoneyInput::format('9999999999999999.99'));
        $this->assertSame('9999999999999999.99', MoneyInput::normalize('9.999.999.999.999.999,99'));
        $this->assertSame('0.05', MoneyInput::normalize('0,05'));
        $this->assertSame('25.1234', MoneyInput::normalize('25,1234'));
        $this->assertSame('1.000,999', MoneyInput::format('1000.999'));
        $this->assertSame('', MoneyInput::format(null));
    }

    public function test_localized_obligations_are_saved_as_decimal_and_cannot_bypass_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $application = Application::factory()->create();
        $data = ['application_id' => $application->id, 'component' => 'Biaya Uji', 'basis' => 'Perjanjian uji', 'agreement_reference' => 'REF-UJI', 'amount' => '1.250.000,50', '_money_fields' => ['amount']];
        $this->post(route('obligations.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('financial_obligations', ['amount' => '1250000.50']);
        $record = DB::table('financial_obligations')->first();
        $response = $this->get(route('obligations.edit', $record->id));
        $response->assertOk()->assertSee('1.250.000,50')->assertSee('data-money="2"', false);
        if (getenv('JM_MONEY_VISUAL_QA')) {
            $html = preg_replace_callback('~https?://[^/" ]+/(css|js)/([^" ]+)~', fn ($match) => 'file://'.public_path($match[1].'/'.$match[2]), $response->getContent());
            file_put_contents('/tmp/jm-money-form.html', $html);
        }
        $this->post(route('obligations.pay', $record->id), ['amount' => '0,05', '_money_fields' => ['amount'], 'paid_at' => now('Asia/Jakarta')->subMinute()->format('Y-m-d\TH:i'), 'reference_number' => 'BAYAR-UJI'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('financial_obligation_payments', ['financial_obligation_id' => $record->id, 'amount' => '0.05']);
        foreach (['1.25', '1.000,999', '-1.000,00', 'Rp 1.000,00'] as $invalid) {
            $this->post(route('obligations.store'), array_replace($data, ['amount' => $invalid]))->assertSessionHasErrors('amount');
        }
        $this->assertDatabaseCount('financial_obligations', 1);
        $this->post(route('obligations.store'), array_replace($data, ['amount' => '10.05', '_money_fields' => []]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('financial_obligations', ['amount' => '10.05']);
    }

    public function test_salary_and_campaign_budget_accept_indonesian_format(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('vacancies.store'), ['code' => 'VAC-MONEY', 'job_order_id' => JobOrder::factory()->create()->id, 'title' => 'Lowongan Uji', 'position_name' => 'Posisi Uji', 'quota' => 10, 'salary' => '1.234.567,89', '_money_fields' => ['salary'], 'currency' => 'IDR', 'status' => 'draft'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vacancies', ['code' => 'VAC-MONEY', 'salary' => '1234567.89', 'quota' => 10]);
        $this->post(route('campaigns.store'), ['code' => 'CMP-MONEY', 'name' => 'Kampanye Uji', 'channel' => 'Instagram', 'status' => 'draft', 'budget' => '2.500.000,75', '_money_fields' => ['budget']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('campaigns', ['code' => 'CMP-MONEY', 'budget' => '2500000.75']);
    }

    public function test_money_component_keeps_other_numeric_inputs_unformatted(): void
    {
        $this->withViewErrors([])->blade('<x-candidate-field name="salary" label="Gaji" type="number" value="1234567.89"/>')->assertSee('1.234.567,89')->assertSee('inputmode="decimal"', false)->assertSee('_money_fields[]', false);
        $this->withViewErrors([])->blade('<x-candidate-field name="quota" label="Kuota" type="number" value="1000"/>')->assertDontSee('data-money')->assertSee('type="number"', false);
    }
}
