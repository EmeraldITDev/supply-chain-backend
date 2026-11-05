<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Services\Finance\FinanceIntegrationService;
use App\Services\Finance\FinancePackageBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceIntegrationServiceTest extends TestCase
{
    public function test_is_configured_requires_base_url_and_api_key(): void
    {
        config([
            'finance_ap.enabled' => true,
            'finance_ap.base_url' => '',
            'finance_ap.api_key' => '',
        ]);

        $service = app(FinanceIntegrationService::class);

        $this->assertFalse($service->isConfigured());

        config([
            'finance_ap.base_url' => 'https://financeap-backend.onrender.com',
            'finance_ap.api_key' => 'test-key',
        ]);

        $this->assertTrue($service->isConfigured());
    }

    public function test_handle_webhook_rejects_invalid_signature(): void
    {
        config(['finance_ap.webhook_secret' => 'secret']);

        $payload = [
            'event_type' => 'approved',
            'scm_transaction_id' => '00000000-0000-4000-8000-000000000001',
            'event_id' => 'evt-1',
        ];

        $service = app(FinanceIntegrationService::class);
        $result = $service->handleWebhook($payload, 'bad-signature', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_SIGNATURE', $result['code']);
    }

    public function test_handle_webhook_rejects_when_secret_not_configured(): void
    {
        config(['finance_ap.webhook_secret' => null]);

        $payload = [
            'event_type' => 'approved',
            'scm_transaction_id' => '00000000-0000-4000-8000-000000000001',
        ];

        $service = app(FinanceIntegrationService::class);
        $result = $service->handleWebhook($payload, 'any', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_SIGNATURE', $result['code']);
    }

    public function test_build_package_delegates_to_builder(): void
    {
        $mrf = new MRF(['mrf_id' => 'MRF-TEST']);
        $expected = ['packageVersion' => 1, 'header' => []];

        $builder = $this->createMock(FinancePackageBuilder::class);
        $builder->expects($this->once())
            ->method('build')
            ->with($mrf, 5, 'vendor_invoice_submitted')
            ->willReturn($expected);

        $service = new FinanceIntegrationService(
            $builder,
            app(\App\Services\WorkflowStateService::class),
            app(\App\Services\FinanceAp\ClosureReadinessService::class),
        );

        $this->assertSame($expected, $service->buildPackage($mrf, 5, 'vendor_invoice_submitted'));
    }

    public function test_build_package_can_use_explicit_version_without_recalculating_schedule(): void
    {
        $mrf = new MRF(['mrf_id' => 'MRF-REFRESH']);
        $expected = ['packageVersion' => 2, 'header' => []];

        $builder = $this->createMock(FinancePackageBuilder::class);
        $builder->expects($this->once())
            ->method('build')
            ->with($mrf, null, 'authorized_refresh', 2, false)
            ->willReturn($expected);

        $service = new FinanceIntegrationService(
            $builder,
            app(\App\Services\WorkflowStateService::class),
            app(\App\Services\FinanceAp\ClosureReadinessService::class),
        );

        $this->assertSame($expected, $service->buildPackage($mrf, null, 'authorized_refresh', 2, false));
    }

    public function test_push_package_skips_when_integration_disabled(): void
    {
        config([
            'finance_ap.enabled' => false,
            'finance_ap.cutover_date' => '2026-01-01',
        ]);

        Http::fake();

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-06-01'),
            'scm_transaction_id' => '00000000-0000-4000-8000-000000000099',
        ]);

        $service = app(FinanceIntegrationService::class);

        $this->assertFalse($service->pushPackage($mrf));
        Http::assertNothingSent();
    }

    public function test_unlinked_case_ownership_is_verified_by_transaction_and_po(): void
    {
        config([
            'finance_ap.enabled' => true,
            'finance_ap.base_url' => 'https://financeap.test',
            'finance_ap.api_key' => 'test-key',
            'finance_ap.paths.package_lookup' => '/api/v1/integrations/scm/packages/{scm_transaction_id}',
        ]);

        $transactionId = '95afefc4-ed87-48af-9f9a-7df5515b237f';
        Http::fake([
            'https://financeap.test/api/v1/integrations/scm/packages/*' => Http::response([
                'data' => [
                    'finance_ap_case_id' => '99',
                    'scm_transaction_id' => $transactionId,
                    'scm_po_number' => 'PO-221026-MARVISGLOBALFURNITURE-0002',
                    'package_version' => 1,
                ],
            ], 200),
        ]);

        $mrf = new MRF([
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-221026-MARVISGLOBALFURNITURE-0002',
        ]);

        $result = app(FinanceIntegrationService::class)->verifyCaseOwnership(
            $mrf,
            '99',
            'PO-221026-MARVISGLOBALFURNITURE-0002',
        );

        $this->assertTrue($result['available']);
        $this->assertTrue($result['verified']);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('X-Api-Key', 'test-key'));
    }
}
