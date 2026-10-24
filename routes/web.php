<?php

use App\Http\Controllers\AccountRecoveryController;
use App\Http\Controllers\AcquisitionController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\AuthenticationSecurityController;
use App\Http\Controllers\BlockerController;
use App\Http\Controllers\CandidateApplicationController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CareJourneyController;
use App\Http\Controllers\CockpitController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ConversationCrmController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CrmBulkController;
use App\Http\Controllers\CrmIdentityController;
use App\Http\Controllers\CrmPipelineWorkspaceController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentCustodyController;
use App\Http\Controllers\DocumentOperationsController;
use App\Http\Controllers\DocumentRequirementController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FlightController;
use App\Http\Controllers\FollowUpWorkspaceController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\FunnelPublicController;
use App\Http\Controllers\GovernanceController;
use App\Http\Controllers\HealthScreeningController;
use App\Http\Controllers\HealthTemplateController;
use App\Http\Controllers\HolidayMobilityController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobOrderController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\MasterWorkflowController;
use App\Http\Controllers\MedicalEventController;
use App\Http\Controllers\MetaConnectController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProcessActionController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\PublicWebsiteController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteBuilderController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrainingActivityController;
use App\Http\Controllers\TrainingBatchController;
use App\Http\Controllers\TrainingEnrollmentController;
use App\Http\Controllers\TravelEventController;
use App\Http\Controllers\VacancyController;
use App\Http\Controllers\WhatsAppQrController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\CrmAccess;
use App\Http\Middleware\PortalAccess;
use Illuminate\Support\Facades\Route;

Route::get('akses-tidak-tersedia', fn () => abort(403, 'Peran atau izin akun belum ditetapkan. Hubungi administrator.'))->middleware('auth')->name('access.unavailable');

Route::get('/', [PublicWebsiteController::class, 'home'])->name('public.home');
Route::get('api/public/qr/{token}', [HolidayMobilityController::class, 'qr'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('qr.api');
Route::get('qr/{token}', [HolidayMobilityController::class, 'qr'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('qr.public');
Route::get('qr/{token}/image', [HolidayMobilityController::class, 'qrImage'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('qr.image');
Route::post('api/public/tracking/consent', [TrackingController::class, 'consent'])->middleware('throttle:30,1')->name('tracking-public.consent');
Route::post('api/public/tracking/events', [TrackingController::class, 'event'])->middleware('throttle:90,1')->name('tracking-public.event');
Route::middleware(['auth', PortalAccess::class])->group(function (): void {
    Route::get('portal/libur', [HolidayMobilityController::class, 'portal'])->name('portal.holiday');
    Route::get('portal/perjalanan', [HolidayMobilityController::class, 'portal'])->name('portal.travel');
    Route::get('portal/perjalanan/{mobility}', [HolidayMobilityController::class, 'show'])->whereNumber('mobility')->name('portal.travel.show');
    Route::post('api/cpmi/leave-requests', [HolidayMobilityController::class, 'leave'])->middleware('throttle:10,1')->name('portal.api-leave');
    Route::post('portal/libur/izin', [HolidayMobilityController::class, 'leave'])->middleware('throttle:10,1')->name('portal.leave');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('api/auth/staff/login', [AuthenticatedSessionController::class, 'store'])->name('auth.staff.login');
});
Route::get('auth/challenge', [AuthenticationSecurityController::class, 'challenge'])->name('auth.challenge');
Route::post('auth/challenge', [AuthenticationSecurityController::class, 'verify'])->block(10, 10)->name('auth.challenge.verify');
Route::post('api/auth/staff/mfa/verify', [AuthenticationSecurityController::class, 'verify'])->block(10, 10)->name('auth.staff.mfa.verify');
Route::post('auth/cancel', [AuthenticationSecurityController::class, 'cancel'])->name('auth.cancel');
Route::middleware('auth')->group(function (): void {
    Route::get('auth/recovery-codes', [AuthenticationSecurityController::class, 'recoveryCodes'])->name('auth.recovery-codes');
    Route::get('auth/sessions', [AuthenticationSecurityController::class, 'sessions'])->name('auth.sessions');
    Route::post('auth/sessions/revoke', [AuthenticationSecurityController::class, 'revoke'])->name('auth.sessions.revoke');
    Route::post('security/users/{user}/revoke-sessions', [AuthenticationSecurityController::class, 'revokeUser'])->name('security.users.revoke');
    Route::get('auth/confirm', [AuthenticationSecurityController::class, 'reauthenticate'])->name('auth.reauthenticate');
    Route::post('auth/confirm', [AuthenticationSecurityController::class, 'confirm'])->name('auth.confirm');
});
Route::get('forgot-password', [AccountRecoveryController::class, 'create'])->name('password.request');
Route::post('forgot-password', [AccountRecoveryController::class, 'store'])->name('password.email');
Route::get('reset-password/{token}', [AccountRecoveryController::class, 'edit'])->name('password.reset');
Route::post('reset-password', [AccountRecoveryController::class, 'update'])->block(10, 10)->name('password.update');

Route::get('funnel-media/{funnel}/{file}', [FunnelController::class, 'media'])->whereNumber('funnel')->where('file', '[a-f0-9-]{36}\\.(jpg|png|webp)')->name('funnel-media.show');

Route::get('daftar/{slug}', [PublicRegistrationController::class, 'show'])->name('registration.show');
Route::post('daftar/{slug}', [PublicRegistrationController::class, 'store'])->middleware('throttle:10,1')->block(10, 10)->name('registration.store');

Route::middleware(['auth', CrmAccess::class])->group(function (): void {
    Route::post('crm/conversations/{conversation}/messages/{message}/retry', [ConversationCrmController::class, 'retryMessage'])->whereNumber(['conversation', 'message'])->name('crm.retry-message');
    Route::post('settings/crm/webhooks/{event}/retry', [ConversationCrmController::class, 'retryEvent'])->whereNumber('event')->name('crm.retry-event');
    Route::post('settings/crm/whatsapp/connect/start', [ConversationCrmController::class, 'connectStart'])->name('crm.connect-start');
    Route::post('settings/crm/whatsapp/connect/callback', [ConversationCrmController::class, 'connectCallback'])->name('crm.connect-callback');
    Route::post('crm/availability', [ConversationCrmController::class, 'availability'])->name('crm.availability');
    Route::get('crm/conversations/{conversation}/messages/{message}/attachment', [ConversationCrmController::class, 'attachment'])->whereNumber(['conversation', 'message'])->middleware('throttle:20,1')->name('crm.attachment');
    Route::post('settings/crm/stages', [ConversationCrmController::class, 'createStage'])->name('crm.create-stage');
    Route::post('settings/crm/stages/{stage}', [ConversationCrmController::class, 'saveStage'])->whereNumber('stage')->name('crm.save-stage');
    Route::post('settings/crm/channels/{channel}/register', [ConversationCrmController::class, 'registerChannel'])->whereNumber('channel')->middleware('throttle:5,1')->name('crm.register-channel');
    Route::prefix('api/whatsapp')->name('whatsapp-qr.')->group(function (): void {
        Route::post('channels/{channel}/remove', [WhatsAppQrController::class, 'remove'])->whereNumber('channel')->middleware('throttle:10,1')->name('remove');
        Route::get('channels', [WhatsAppQrController::class, 'index'])->name('index');
        Route::post('channels', [WhatsAppQrController::class, 'create'])->middleware('throttle:10,1')->name('create');
        Route::get('pairing/{pairing}/qr', [WhatsAppQrController::class, 'image'])->whereUuid('pairing')->middleware('throttle:30,1')->name('image');
        Route::get('channels/{channel}/qr/status', [WhatsAppQrController::class, 'status'])->whereNumber('channel')->middleware('throttle:60,1')->name('status');
        Route::get('channels/{channel}/diagnostics', [WhatsAppQrController::class, 'diagnostics'])->whereNumber('channel')->name('diagnostics');
        foreach (['start', 'rescan', 'disconnect', 'routing', 'migrate'] as $action) {
            Route::post('channels/{channel}/'.$action, [WhatsAppQrController::class, $action])->whereNumber('channel')->middleware('throttle:10,1')->name($action);
        }
    });
    Route::prefix('api/integrations/whatsapp/meta')->name('meta-connect.')->group(function (): void {
        Route::get('preflight', [MetaConnectController::class, 'preflight'])->name('preflight');
        Route::post('connect/start', [MetaConnectController::class, 'start'])->middleware('throttle:10,1')->name('start');
        Route::get('callback', [MetaConnectController::class, 'callback'])->middleware('throttle:30,1')->name('callback');
        Route::get('connect/{session}', [MetaConnectController::class, 'state'])->whereUuid('session')->name('state');
        foreach (['businesses', 'wabas', 'phones'] as $action) {
            Route::get('connect/{session}/'.$action, [MetaConnectController::class, $action])->whereUuid('session')->middleware('throttle:60,1')->name($action);
        }
        foreach (['select', 'finalize', 'register'] as $action) {
            Route::post('connect/{session}/'.$action, [MetaConnectController::class, $action])->whereUuid('session')->middleware('throttle:10,1')->name($action);
        }
    });
    foreach (['health', 'test-message', 'disconnect', 'routing'] as $action) {
        Route::post('api/integrations/whatsapp/{channel}/'.$action, [MetaConnectController::class, $action === 'test-message' ? 'testMessage' : $action])->whereNumber('channel')->middleware('throttle:10,1')->name('meta-connect.'.$action);
    }
    Route::get('api/integrations/whatsapp/{channel}/tests', [MetaConnectController::class, 'tests'])->whereNumber('channel')->name('meta-connect.tests');
    Route::get('crm/inbox', [ConversationCrmController::class, 'inbox'])->name('crm.inbox');
    Route::get('api/crm/conversations', [ConversationCrmController::class, 'inbox'])->name('crm.api-inbox');
    Route::post('api/crm/pipeline/export-preview', [CrmPipelineWorkspaceController::class, 'previewExport'])->name('crm.export-preview');
    Route::post('api/crm/pipeline/stages/order', [CrmPipelineWorkspaceController::class, 'reorder'])->name('crm.reorder-stages');
    Route::post('api/crm/pipeline/bulk', [CrmPipelineWorkspaceController::class, 'bulk'])->middleware('throttle:20,1')->name('crm.bulk');
    Route::get('api/crm/pipeline/exports/{job}/download', [CrmPipelineWorkspaceController::class, 'download'])->whereUuid('job')->name('crm.export-download');
    Route::get('api/crm/pipeline/jobs/{job}', [CrmPipelineWorkspaceController::class, 'job'])->whereUuid('job')->name('crm.bulk-job');
    Route::match(['get', 'put'], 'api/crm/card-layout', [CrmPipelineWorkspaceController::class, 'layout'])->name('crm.card-layout');
    Route::get('crm/campaign-attribution', [CrmIdentityController::class, 'campaign'])->name('crm.campaign-attribution');
    Route::post('crm/leads/import', [CrmIdentityController::class, 'import'])->middleware('throttle:5,1')->name('crm.import');
    Route::get('crm/duplicates', [CrmIdentityController::class, 'index'])->name('crm.duplicates');
    Route::get('crm/duplicates/{duplicate}', [CrmIdentityController::class, 'compare'])->whereNumber('duplicate')->name('crm.duplicate-compare');
    Route::post('crm/duplicates/{duplicate}', [CrmIdentityController::class, 'resolve'])->whereNumber('duplicate')->name('crm.duplicate-resolve');
    Route::get('crm/leads/{lead}/attribution', [CrmIdentityController::class, 'attribution'])->whereNumber('lead')->name('crm.attribution');
    Route::get('crm/pipeline', [ConversationCrmController::class, 'pipeline'])->name('crm.pipeline');
    Route::get('crm/follow-up', [ConversationCrmController::class, 'followUps'])->name('crm.followups');
    Route::get('crm/leads/{lead}', [ConversationCrmController::class, 'profile'])->whereNumber('lead')->name('crm.profile');
    Route::post('crm/leads', [ConversationCrmController::class, 'createLead'])->name('crm.create-lead');
    foreach (['send', 'assign', 'takeover', 'close', 'read', 'claim'] as $action) {
        Route::post('crm/conversations/{conversation}/'.$action, [ConversationCrmController::class, $action === 'takeover' ? 'assign' : $action])->whereNumber('conversation')->middleware($action === 'send' ? 'throttle:30,1' : 'throttle:120,1')->name('crm.'.$action);
    }
    foreach (['move', 'tag', 'followup', 'convert', 'archive'] as $action) {
        Route::post('crm/leads/{lead}/'.$action, [ConversationCrmController::class, $action === 'followup' ? 'followUp' : $action])->whereNumber('lead')->name('crm.'.$action);
    }
    Route::post('crm/leads/{lead}/followups/{followup}', [ConversationCrmController::class, 'finishFollowUp'])->whereNumber(['lead', 'followup'])->name('crm.finish-followup');
    Route::post('crm/pipeline/views', [ConversationCrmController::class, 'savedView'])->name('crm.saved-view');
    Route::post('settings/crm/agents', [ConversationCrmController::class, 'saveAgent'])->name('crm.save-agent');
    Route::get('settings/crm', [ConversationCrmController::class, 'settings'])->name('crm.settings');
    Route::post('settings/crm/channels', [ConversationCrmController::class, 'channel'])->name('crm.channel');
    Route::post('settings/crm/channels/{channel}/test', [ConversationCrmController::class, 'testChannel'])->whereNumber('channel')->name('crm.test-channel');
    Route::post('settings/crm/channels/{channel}/templates', [ConversationCrmController::class, 'syncTemplates'])->whereNumber('channel')->name('crm.sync-templates');
    Route::post('settings/crm/tags', [ConversationCrmController::class, 'saveTag'])->name('crm.save-tag');
    Route::post('settings/crm/quick-replies', [ConversationCrmController::class, 'saveQuickReply'])->name('crm.save-quick-reply');
    Route::get('api/ops/holidays', [HolidayMobilityController::class, 'index'])->name('holiday-transport.api-index');
    Route::get('api/ops/holiday-eligibility', [HolidayMobilityController::class, 'index'])->name('holiday-transport.api-index-eligibility');
    Route::post('api/ops/holidays', [HolidayMobilityController::class, 'holiday'])->name('holiday-transport.api-holiday');
    Route::patch('api/ops/holidays/{holiday}', [HolidayMobilityController::class, 'holiday'])->whereNumber('holiday')->name('holiday-transport.api-holiday-update');
    Route::patch('api/ops/leave-requests/{leave}/review', [HolidayMobilityController::class, 'review'])->whereNumber('leave')->name('holiday-transport.api-review');
    Route::get('api/ops/mobility', [HolidayMobilityController::class, 'index'])->name('mobility.api-show-list');
    Route::post('api/ops/mobility', [HolidayMobilityController::class, 'storeMobility'])->name('mobility.api-store');
    Route::patch('api/ops/mobility/{mobility}', [HolidayMobilityController::class, 'updateMobility'])->whereNumber('mobility')->name('mobility.api-update');
    Route::post('api/ops/transport-batches', [HolidayMobilityController::class, 'batch'])->name('mobility.api-batch');
    Route::patch('api/ops/transport-batches/{batch}', [HolidayMobilityController::class, 'batch'])->whereNumber('batch')->name('mobility.api-batch-update');
    Route::post('api/ops/qr-passes', [HolidayMobilityController::class, 'issuePayload'])->name('mobility.api-issue-body');
    Route::post('api/ops/handovers', [HolidayMobilityController::class, 'handoverPayload'])->name('mobility.api-handover-body');
    Route::patch('api/ops/handovers/{item}', [HolidayMobilityController::class, 'handoverPayload'])->whereNumber('item')->name('mobility.api-handover-update');
    Route::post('api/ops/qr-passes/legs/{leg}', [HolidayMobilityController::class, 'issue'])->whereNumber('leg')->name('mobility.api-issue');
    Route::post('api/ops/qr/{token}/check-in', [HolidayMobilityController::class, 'checkIn'])->middleware('throttle:30,1')->name('mobility.api-check-in');
    Route::post('api/ops/handovers/{mobility}', [HolidayMobilityController::class, 'handover'])->whereNumber('mobility')->name('mobility.api-handover');
    Route::get('settings/tracking', [TrackingController::class, 'settings'])->name('tracking-settings.index');
    Route::post('settings/tracking', [TrackingController::class, 'destination'])->name('tracking-settings.store');
    Route::patch('settings/tracking/{destination}', [TrackingController::class, 'destination'])->whereNumber('destination')->name('tracking-settings.update');
    Route::post('settings/tracking/{destination}/test', [TrackingController::class, 'testDestination'])->whereNumber('destination')->name('tracking-settings.test');
    Route::get('settings/tracking/diagnostics', [TrackingController::class, 'index'])->name('tracking-settings.diagnostics');
    Route::post('settings/tracking/mappings', [TrackingController::class, 'mapping'])->name('tracking-settings.mapping');
    Route::get('api/tracking/event-catalog', [TrackingController::class, 'catalog'])->name('tracking-settings.catalog');
    Route::get('api/settings/tracking-destinations', [TrackingController::class, 'settings'])->name('tracking-settings.api-index');
    Route::post('api/settings/tracking-destinations', [TrackingController::class, 'destination'])->name('tracking-settings.api-store');
    Route::patch('api/settings/tracking-destinations/{destination}', [TrackingController::class, 'destination'])->whereNumber('destination')->name('tracking-settings.api-update');
    Route::post('api/settings/tracking-destinations/{destination}/test', [TrackingController::class, 'testDestination'])->whereNumber('destination')->middleware('throttle:20,1')->name('tracking-settings.api-test');
    Route::get('api/settings/tracking/diagnostics', [TrackingController::class, 'index'])->name('tracking-settings.api-diagnostics');
    Route::get('api/funnels/{funnel}/tracking', [TrackingController::class, 'funnelConfiguration'])->whereNumber('funnel')->name('funnel-tracking.show');
    Route::put('api/funnels/{funnel}/tracking', [TrackingController::class, 'funnel'])->whereNumber('funnel')->name('funnel-tracking.update');
    Route::post('api/funnels/{funnel}/tracking/test', [TrackingController::class, 'testFunnel'])->whereNumber('funnel')->middleware('throttle:20,1')->name('funnel-tracking.test');
    Route::post('api/funnels/{funnel}/sections/{section}/tracking', [TrackingController::class, 'addSectionRule'])->whereNumber('funnel')->name('funnel-tracking.section-store');
    Route::delete('api/funnels/{funnel}/sections/{section}/tracking/{rule}', [TrackingController::class, 'removeSectionRule'])->whereNumber('funnel')->where('rule', '[A-Za-z0-9_-]{1,40}')->name('funnel-tracking.section-destroy');
    Route::get('api/admin/tracking/destinations', [TrackingController::class, 'index'])->name('tracking.api-integrations');
    Route::post('api/admin/tracking/destinations', [TrackingController::class, 'destination'])->name('tracking.api-destination.store');
    Route::patch('api/admin/tracking/destinations/{destination}', [TrackingController::class, 'destination'])->whereNumber('destination')->name('tracking.api-destination.update');
    Route::get('api/admin/tracking/event-mappings', [TrackingController::class, 'index'])->name('tracking.api-index');
    Route::post('api/admin/tracking/event-mappings', [TrackingController::class, 'mapping'])->name('tracking.api-mapping');
    Route::patch('api/admin/tracking/event-mappings/{mapping}', [TrackingController::class, 'mapping'])->whereNumber('mapping')->name('tracking.api-mapping-update');
    Route::post('api/admin/tracking/test-event', [TrackingController::class, 'test'])->name('tracking.api-test');
    Route::get('api/admin/tracking/diagnostics', [TrackingController::class, 'index'])->name('tracking.api-diagnostics');
    Route::get('api/admin/tracking/delivery-attempts', [TrackingController::class, 'index'])->name('tracking.api-attempts');

    Route::post('ops/bulk', [HolidayMobilityController::class, 'bulk'])->name('holiday-transport.bulk');
    Route::get('ops/holiday-transport', [HolidayMobilityController::class, 'index'])->name('holiday-transport.index');
    Route::post('ops/holidays', [HolidayMobilityController::class, 'holiday'])->name('holiday-transport.holiday');
    Route::patch('ops/holidays/{holiday}', [HolidayMobilityController::class, 'holiday'])->whereNumber('holiday')->name('holiday-transport.holiday-update');
    Route::post('ops/holiday-policies', [HolidayMobilityController::class, 'policy'])->name('holiday-transport.policy');
    Route::post('ops/leave-requests', [HolidayMobilityController::class, 'leave'])->name('holiday-transport.leave');
    Route::post('ops/leave-requests/{leave}/review', [HolidayMobilityController::class, 'review'])->whereNumber('leave')->name('holiday-transport.review');
    Route::post('ops/mobility', [HolidayMobilityController::class, 'storeMobility'])->name('mobility.store');
    Route::patch('ops/mobility/{mobility}', [HolidayMobilityController::class, 'updateMobility'])->whereNumber('mobility')->name('mobility.update');
    Route::get('ops/mobility/{mobility}', [HolidayMobilityController::class, 'show'])->whereNumber('mobility')->name('mobility.show');
    Route::post('ops/mobility/legs/{leg}', [HolidayMobilityController::class, 'leg'])->whereNumber('leg')->name('mobility.leg');
    Route::post('ops/pickup-points', [HolidayMobilityController::class, 'point'])->name('mobility.point');
    Route::patch('ops/pickup-points/{point}', [HolidayMobilityController::class, 'point'])->whereNumber('point')->name('mobility.point-update');
    Route::post('ops/transport-batches', [HolidayMobilityController::class, 'batch'])->name('mobility.batch');
    Route::patch('ops/transport-batches/{batch}', [HolidayMobilityController::class, 'batch'])->whereNumber('batch')->name('mobility.batch-update');
    Route::get('ops/transport-batches/{batch}/manifest', [HolidayMobilityController::class, 'manifest'])->whereNumber('batch')->name('mobility.manifest');
    Route::post('ops/qr-passes/legs/{leg}', [HolidayMobilityController::class, 'issue'])->whereNumber('leg')->name('mobility.issue');
    Route::post('ops/qr-passes/{pass}/revoke', [HolidayMobilityController::class, 'revoke'])->whereNumber('pass')->name('mobility.revoke');
    Route::post('ops/qr/{token}/check-in', [HolidayMobilityController::class, 'checkIn'])->middleware('throttle:30,1')->name('mobility.check-in');
    Route::post('ops/mobility/{mobility}/handover', [HolidayMobilityController::class, 'handover'])->whereNumber('mobility')->name('mobility.handover');
    Route::get('marketing/event-tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('admin/tracking/integrations', [TrackingController::class, 'index'])->name('tracking.integrations');
    Route::post('admin/tracking/destinations', [TrackingController::class, 'destination'])->name('tracking.destination.store');
    Route::patch('admin/tracking/destinations/{destination}', [TrackingController::class, 'destination'])->whereNumber('destination')->name('tracking.destination.update');
    Route::post('admin/tracking/event-mappings', [TrackingController::class, 'mapping'])->name('tracking.mapping');
    Route::post('admin/tracking/test-event', [TrackingController::class, 'test'])->name('tracking.test');
    Route::post('admin/tracking/outbox/{outbox}/retry', [TrackingController::class, 'retry'])->whereNumber('outbox')->name('tracking.retry');

    Route::get('admin/site', [SiteBuilderController::class, 'index'])->name('site-builder.index');
    Route::post('admin/site', [SiteBuilderController::class, 'store'])->name('site-builder.store');
    Route::get('admin/site/assets', [SiteBuilderController::class, 'assets'])->name('site-assets.index');
    Route::post('admin/site/assets', [SiteBuilderController::class, 'upload'])->name('site-assets.upload');
    Route::patch('admin/site/assets/{asset}', [SiteBuilderController::class, 'updateAsset'])->whereNumber('asset')->name('site-assets.update');
    Route::delete('admin/site/assets/{asset}', [SiteBuilderController::class, 'deleteAsset'])->whereNumber('asset')->name('site-assets.delete');
    Route::get('admin/site/health', [SiteBuilderController::class, 'health'])->name('site-health.index');
    Route::get('admin/site/analytics', [SiteBuilderController::class, 'analytics'])->name('site-analytics.index');
    Route::get('admin/site/vacancies/{vacancy}', [SiteBuilderController::class, 'vacancy'])->name('site-vacancies.edit');
    Route::post('admin/site/vacancies/{vacancy}', [SiteBuilderController::class, 'publishVacancy'])->name('site-vacancies.publish');
    Route::get('admin/site/{page}/edit', [SiteBuilderController::class, 'edit'])->whereNumber('page')->name('site-builder.edit');
    Route::post('admin/site/{page}/live-preview', [SiteBuilderController::class, 'preview'])->whereNumber('page')->name('site-builder.live-preview');
    Route::get('admin/site/{page}/preview', [SiteBuilderController::class, 'preview'])->whereNumber('page')->name('site-builder.preview');
    foreach (['update', 'publish', 'restore', 'clone', 'archive', 'slug'] as $action) {
        Route::post('admin/site/{page}/'.$action.($action === 'restore' ? '/{version}' : ''), [SiteBuilderController::class, $action])->whereNumber('page')->name('site-builder.'.$action);
    }

    Route::get('cockpit/{role}', [CockpitController::class, 'index'])->name('cockpit.index');
    Route::post('admin/funnels/{funnel}/tracking', [TrackingController::class, 'funnel'])->whereNumber('funnel')->name('tracking.funnel');
    Route::get('admin/funnels', [FunnelController::class, 'index'])->name('funnels.index');
    Route::post('admin/funnels', [FunnelController::class, 'store'])->name('funnels.store');
    foreach (['builder', 'preview', 'responses', 'analytics'] as $action) {
        Route::get('admin/funnels/{funnel}/'.$action, [FunnelController::class, $action])->whereNumber('funnel')->name('funnels.'.$action);
    }
    Route::get('admin/funnels/{funnel}/responses/export', [FunnelController::class, 'responses'])->whereNumber('funnel')->name('funnels.export');
    foreach (['update', 'publish', 'clone', 'test'] as $action) {
        Route::post('admin/funnels/{funnel}/'.$action, [FunnelController::class, $action])->whereNumber('funnel')->name('funnels.'.$action);
    }
    Route::post('admin/funnels/{funnel}/upload-media', [FunnelController::class, 'uploadMedia'])->whereNumber('funnel')->middleware('throttle:30,1')->name('funnels.upload-media');
    Route::post('admin/funnels/{funnel}/readiness', [FunnelController::class, 'readiness'])->whereNumber('funnel')->name('funnels.readiness');
    Route::post('admin/funnels/{funnel}/live-preview', [FunnelController::class, 'livePreview'])->whereNumber('funnel')->name('funnels.live-preview');
    Route::post('admin/funnels/{funnel}/versions/{version}/rollback', [FunnelController::class, 'rollback'])->whereNumber(['funnel', 'version'])->name('funnels.rollback');
    Route::get('admin/cs-rotator', [AcquisitionController::class, 'groups'])->name('acquisition.groups');
    foreach (['saveGroup', 'member', 'mapping'] as $action) {
        Route::post('admin/cs-rotator/'.$action, [AcquisitionController::class, $action])->name('acquisition.'.$action);
    }
    Route::get('admin/whatsapp-templates', [AcquisitionController::class, 'templates'])->name('acquisition.templates');
    Route::post('admin/whatsapp-templates', [AcquisitionController::class, 'saveTemplate'])->name('acquisition.saveTemplate');
    Route::get('api/crm/leads', [AcquisitionController::class, 'leads'])->name('acquisition.api-leads');
    Route::post('cs/leads/{lead}/schedule', [AcquisitionController::class, 'schedule'])->whereNumber('lead')->name('acquisition.schedule');
    Route::get('cs/leads', [AcquisitionController::class, 'leads'])->name('acquisition.leads');
    Route::get('cs/leads/{lead}/detail', [AcquisitionController::class, 'detail'])->whereNumber('lead')->name('acquisition.detail');
    Route::get('cs/leads/{lead}', [AcquisitionController::class, 'show'])->whereNumber('lead')->name('acquisition.show');
    foreach (['qualify', 'convert', 'reassign', 'whatsapp', 'followup'] as $action) {
        Route::post('cs/leads/{lead}/'.$action, [AcquisitionController::class, $action])->whereNumber('lead')->name('acquisition.'.$action);
    }
    Route::get('admin/access', [GovernanceController::class, 'index'])->name('governance.index');
    foreach (['assignment', 'grantRequest', 'delegation'] as $action) {
        Route::post('admin/access/'.$action, [GovernanceController::class, $action])->name('governance.'.$action);
    }
    foreach (['approve', 'revoke'] as $action) {
        Route::post('admin/access/grants/{grant}/'.$action, [GovernanceController::class, $action])->whereNumber('grant')->name('governance.'.$action);
    }
    Route::post('admin/access/assignments/{assignment}/revoke', [GovernanceController::class, 'revokeAssignment'])->whereNumber('assignment')->name('governance.revokeAssignment');
    Route::post('calon-pekerja/{candidate}/portal-invite', [PortalController::class, 'invite'])->name('portal-admin.invite');
    Route::get('permintaan-cpmi/{record}/detail', [PortalController::class, 'staffRequestDetail'])->whereNumber('record')->name('portal-admin.request-detail');
    Route::get('api/crm/cpmi-requests', [PortalController::class, 'staffRequests'])->name('portal-admin.api-requests');
    Route::get('permintaan-cpmi', [PortalController::class, 'staffRequests'])->name('portal-admin.requests');
    Route::post('permintaan-cpmi/{record}', [PortalController::class, 'review'])->whereNumber('record')->name('portal-admin.review');
    Route::get('dasbor', [CockpitController::class, 'index'])->name('dashboard');

    foreach (array_keys(ReportController::MODULES) as $module) {
        Route::get('laporan/'.$module, [ReportController::class, 'index'])->defaults('module', $module)->name('reports.'.$module.'.index');
    }
    foreach (array_keys(SettingsController::MODULES) as $module) {
        Route::get('pengaturan/'.$module, [SettingsController::class, 'index'])->defaults('module', $module)->name('settings.'.$module.'.index');
        if ($module === 'activity') {
            continue;
        }
        Route::post('pengaturan/'.$module, [SettingsController::class, 'store'])->defaults('module', $module)->name('settings.'.$module.'.store');
        if ($module !== 'general') {
            Route::get('pengaturan/'.$module.'/create', [SettingsController::class, 'create'])->defaults('module', $module)->name('settings.'.$module.'.create');
            Route::get('pengaturan/'.$module.'/{record}/edit', [SettingsController::class, 'edit'])->defaults('module', $module)->whereNumber('record')->name('settings.'.$module.'.edit');
            Route::put('pengaturan/'.$module.'/{record}', [SettingsController::class, 'update'])->defaults('module', $module)->whereNumber('record')->name('settings.'.$module.'.update');
        }
    }
    Route::post('pengaturan/automation/{record}/run', [SettingsController::class, 'run'])->whereNumber('record')->name('settings.automation.run');

    Route::get('modul/{module}', function (string $module) {
        foreach (config('navigation') as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === $module) {
                    if ($item['route'] !== 'modules.show') {
                        return to_route($item['route'], array_merge($item['parameters'] ?? [], ['menu' => $item['key']]));
                    }

                    $relatedItems = array_filter($group['items'], fn (array $related): bool => $related['route'] !== 'modules.show');

                    return view('modules.show', ['item' => $item, 'group' => $group, 'relatedItems' => $relatedItems]);
                }
            }
        }

        abort(404);
    })->name('modules.show');

    foreach (['campaigns' => 'kampanye', 'landing-pages' => 'halaman-pendaftaran'] as $module => $path) {
        foreach (['index' => ['GET', ''], 'create' => ['GET', '/create'], 'store' => ['POST', ''], 'edit' => ['GET', '/{record}/edit'], 'update' => ['PUT', '/{record}']] as $action => [$verb, $suffix]) {
            Route::match([$verb], $path.$suffix, [RecruitmentController::class, $action])->defaults('module', $module === 'campaigns' ? 'campaigns' : 'landing_pages')->whereNumber('record')->name($module.'.'.$action);
        }
    }

    foreach (['agreements', 'milestones', 'receivables', 'sponsor-payments', 'benefits', 'obligations', 'settlements'] as $module) {
        Route::get('keuangan/'.$module, [FinanceController::class, 'index'])->defaults('module', $module)->name($module.'.index');
        if ($module === 'settlements') {
            continue;
        }
        Route::get('keuangan/'.$module.'/create', [FinanceController::class, 'create'])->defaults('module', $module)->name($module.'.create');
        Route::post('keuangan/'.$module, [FinanceController::class, 'store'])->defaults('module', $module)->name($module.'.store');
        if ($module !== 'sponsor-payments') {
            Route::get('keuangan/'.$module.'/{record}/edit', [FinanceController::class, 'edit'])->defaults('module', $module)->whereNumber('record')->name($module.'.edit');
            Route::put('keuangan/'.$module.'/{record}', [FinanceController::class, 'update'])->defaults('module', $module)->whereNumber('record')->name($module.'.update');
        }
        if (in_array($module, ['benefits', 'obligations'])) {
            Route::post('keuangan/'.$module.'/{record}/pay', [FinanceController::class, 'payment'])->defaults('module', $module)->whereNumber('record')->name($module.'.pay');
        }
    }
    foreach (['sponsor', 'benefit', 'obligation'] as $kind) {
        Route::get('bukti-keuangan/'.$kind.'/{record}', [FinanceController::class, 'file'])->defaults('file_kind', $kind)->whereNumber('record')->name('finance-file.'.$kind);
    }
    Route::get('admin/site-asset-picker', [SiteBuilderController::class, 'assets'])->middleware('throttle:60,1')->name('site-builder.asset-picker');
    Route::get('admin/funnel-vacancies', [RecruitmentController::class, 'vacancies'])->middleware('throttle:60,1')->name('funnels.vacancies');
    Route::get('pemasaran/campaign-vacancies', [RecruitmentController::class, 'vacancies'])->middleware('throttle:60,1')->name('campaigns.vacancies');
    Route::get('pemasaran/referral-candidates', [MarketingController::class, 'candidates'])->middleware('throttle:60,1')->name('referrals.candidates');
    foreach (['sources' => 'lead-sources', 'referrals' => 'referrals', 'analytics' => 'marketing-analytics'] as $mode => $prefix) {
        Route::get('pemasaran/'.$prefix, [MarketingController::class, 'index'])->defaults('mode', $mode)->name($prefix.'.index');
        if ($mode !== 'analytics') {
            Route::post('pemasaran/'.$prefix, [MarketingController::class, 'store'])->defaults('mode', $mode)->name($prefix.'.store');
        }
    }

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('calon-pekerja/{candidate}/cetak', [CandidateController::class, 'print'])->name('candidates.print');
    Route::post('calon-pekerja/{candidate}/archive', [CandidateController::class, 'archive'])->name('candidates.archive');
    Route::resource('calon-pekerja', CandidateController::class)->parameters(['calon-pekerja' => 'candidate'])->names('candidates');

    Route::resource('mitra-sponsor', CompanyController::class)->parameters(['mitra-sponsor' => 'company'])->names('companies');

    Route::resource('permintaan-tenaga-kerja', JobOrderController::class)->parameters(['permintaan-tenaga-kerja' => 'job_order'])->names('job-orders');

    Route::resource('admin/lowongan', VacancyController::class)->parameters(['lowongan' => 'vacancy'])->names('vacancies');

    Route::get('lamaran', ApplicationController::class.'@index')->name('applications.index');
    Route::get('lamaran/{application}', ApplicationController::class.'@show')->name('applications.show');
    Route::patch('lamaran/{application}/cs', ApplicationController::class.'@assignCs')->name('applications.assign-cs');
    Route::patch('lamaran/{application}/status', ApplicationController::class.'@updateStatus')->name('applications.update-status');

    Route::get('crm/process', [PipelineController::class, 'index'])->name('pipeline.index');
    Route::get('alur-proses', [PipelineController::class, 'index'])->name('pipeline.legacy');
    Route::get('api/crm/process/{widget}', [PipelineController::class, 'api'])->whereIn('widget', ['summary', 'stages', 'rows', 'priorities', 'follow-ups', 'filters'])->name('pipeline.api');
    Route::patch('api/crm/process/{application}/priority', [ProcessActionController::class, 'update'])->name('pipeline.priority');
    Route::post('crm/process/events', [ProcessActionController::class, 'event'])->name('pipeline.event');
    Route::get('crm/process/{application}/call', [ProcessActionController::class, 'contact'])->name('pipeline.contact');
    Route::post('crm/process/bulk', [ProcessActionController::class, 'bulk'])->name('pipeline.bulk');
    Route::post('crm/process/views', [ProcessActionController::class, 'savedView'])->name('pipeline.save-view');
    Route::delete('crm/process/views/{view}', [ProcessActionController::class, 'deleteView'])->name('pipeline.delete-view');
    Route::post('crm/process/messages/{draft}/open', [ProcessActionController::class, 'message'])->name('pipeline.message');
    Route::get('alur-proses/{application}/konfirmasi', [PipelineController::class, 'confirm'])->name('pipeline.confirm');
    Route::patch('alur-proses/{application}/tahap', [PipelineController::class, 'move'])->name('pipeline.move');
    Route::get('tugas/insight', [TaskController::class, 'insights'])->name('tasks.insights');
    Route::post('tugas/bulk', [CrmBulkController::class, 'apply'])->name('tasks.bulk');
    Route::get('kendala/insight', [BlockerController::class, 'insights'])->name('blockers.insights');
    Route::post('kendala/bulk', [CrmBulkController::class, 'apply'])->name('blockers.bulk');
    Route::get('api/crm/candidates', [CandidateController::class, 'index'])->name('candidates.api-index');
    Route::get('api/crm/applications', [ApplicationController::class, 'index'])->name('applications.api-index');
    Route::get('api/crm/tasks', [TaskController::class, 'index'])->name('tasks.api-index');
    Route::get('api/crm/blockers', [BlockerController::class, 'index'])->name('blockers.api-index');
    Route::get('calon-pekerja/{candidate}/buat-lamaran', [CandidateApplicationController::class, 'create'])->name('candidates.create-application');
    Route::post('calon-pekerja/{candidate}/buat-lamaran', [CandidateApplicationController::class, 'store'])->name('candidates.store-application');
    Route::resource('tugas', TaskController::class)->only(['index', 'create', 'store', 'edit', 'update'])->parameters(['tugas' => 'task'])->names('tasks');
    Route::patch('tugas/{task}/selesai', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::resource('kendala', BlockerController::class)->except('destroy')->parameters(['kendala' => 'blocker'])->names('blockers');
    Route::patch('kendala/{blocker}/selesai', [BlockerController::class, 'resolve'])->name('blockers.resolve');
    Route::get('master/pemeriksaan-kesehatan/acuan', [HealthTemplateController::class, 'references'])->name('health-templates.references');
    Route::get('master/pemeriksaan-kesehatan', [HealthTemplateController::class, 'index'])->name('health-templates.index');
    Route::get('master/pemeriksaan-kesehatan/tambah', [HealthTemplateController::class, 'show'])->name('health-templates.create');
    Route::post('master/pemeriksaan-kesehatan', [HealthTemplateController::class, 'save'])->name('health-templates.store');
    Route::post('master/pemeriksaan-kesehatan/pertanyaan', [HealthTemplateController::class, 'question'])->name('health-templates.question');
    Route::get('master/pemeriksaan-kesehatan/{template}', [HealthTemplateController::class, 'show'])->name('health-templates.show');
    Route::put('master/pemeriksaan-kesehatan/{template}', [HealthTemplateController::class, 'save'])->name('health-templates.update');
    Route::post('master/pemeriksaan-kesehatan/{template}/terbit', [HealthTemplateController::class, 'publish'])->name('health-templates.publish');
    Route::get('master/kebutuhan-dokumen/acuan', [DocumentRequirementController::class, 'references'])->name('document-requirements.references');
    Route::get('master/kebutuhan-dokumen', [DocumentRequirementController::class, 'index'])->name('document-requirements.index');
    Route::get('master/kebutuhan-dokumen/tambah', [DocumentRequirementController::class, 'show'])->name('document-requirements.create');
    Route::post('master/kebutuhan-dokumen', [DocumentRequirementController::class, 'save'])->name('document-requirements.store');
    Route::get('master/kebutuhan-dokumen/{set}', [DocumentRequirementController::class, 'show'])->name('document-requirements.show');
    Route::put('master/kebutuhan-dokumen/{set}', [DocumentRequirementController::class, 'save'])->name('document-requirements.update');
    Route::post('master/kebutuhan-dokumen/{set}/terbit', [DocumentRequirementController::class, 'publish'])->name('document-requirements.publish');
    Route::get('pemeriksaan-awal-kesehatan', [HealthScreeningController::class, 'index'])->name('health-screenings.index');
    Route::get('pemeriksaan-awal-kesehatan/{application}', [HealthScreeningController::class, 'show'])->name('health-screenings.show');
    Route::put('pemeriksaan-awal-kesehatan/{application}', [HealthScreeningController::class, 'update'])->name('health-screenings.update');
    Route::post('pemeriksaan-awal-kesehatan/{application}/mulai', [HealthScreeningController::class, 'start'])->name('health-screenings.start');
    Route::post('pemeriksaan-awal-kesehatan/{application}/review', [HealthScreeningController::class, 'review'])->name('health-screenings.review');
    Route::resource('mcu', MedicalEventController::class)->only(['index', 'create', 'store', 'edit', 'update'])->parameters(['mcu' => 'medical_event'])->names('medical-events');

    Route::get('kualifikasi', [QualificationController::class, 'index'])->name('qualifications.index');
    Route::get('kualifikasi/{application}', [QualificationController::class, 'show'])->name('qualifications.show');
    Route::put('kualifikasi/{application}', [QualificationController::class, 'update'])->name('qualifications.update');
    Route::get('verifikasi-dokumen', [DocumentController::class, 'verification'])->name('document-verifications.index');
    Route::get('dokumen-kurang', [DocumentController::class, 'missing'])->name('missing-documents.index');
    Route::get('dokumen-kedaluwarsa', [DocumentController::class, 'expiry'])->name('expired-documents.index');
    Route::get('dokumen-asli', [DocumentCustodyController::class, 'index'])->name('document-custodies.index');
    Route::post('dokumen-asli', [DocumentCustodyController::class, 'store'])->name('document-custodies.store');
    Route::get('dokumen-asli/{custody}', [DocumentCustodyController::class, 'show'])->whereNumber('custody')->name('document-custodies.show');
    Route::patch('dokumen-asli/{custody}', [DocumentCustodyController::class, 'update'])->whereNumber('custody')->name('document-custodies.update');
    Route::get('bukti-serah-terima/{history}', [DocumentCustodyController::class, 'downloadEvidence'])->whereNumber('history')->name('document-custodies.evidence');

    Route::get('documents/review', [DocumentController::class, 'verification'])->name('document-operations.inbox');
    Route::get('dokumen/{document}/ruang-review', [DocumentOperationsController::class, 'workspace'])->name('document-operations.workspace');
    Route::get('dokumen/{document}/pratinjau', [DocumentOperationsController::class, 'preview'])->name('document-operations.preview');
    Route::patch('dokumen/{document}/pemilik-tindakan', [DocumentOperationsController::class, 'owner'])->name('document-operations.owner');
    Route::post('dokumen/lamaran/{application}/tindakan', [DocumentOperationsController::class, 'action'])->name('document-operations.action');
    Route::post('dokumen/lamaran/{application}/pengecualian', [DocumentOperationsController::class, 'exception'])->name('document-operations.exception');
    Route::post('dokumen/lamaran/{application}/pengecualian/{exception}', [DocumentOperationsController::class, 'approveException'])->whereNumber('exception')->name('document-operations.approve-exception');
    Route::post('dokumen/reminder', [DocumentOperationsController::class, 'reminder'])->name('document-operations.reminder');
    Route::get('dokumen/reminder', [DocumentOperationsController::class, 'followups'])->name('document-operations.followups');
    Route::post('dokumen/reminder/{followup}/whatsapp', [DocumentOperationsController::class, 'whatsapp'])->whereNumber('followup')->name('document-operations.whatsapp');
    Route::get('dokumen', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('dokumen/lamaran/{application}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('dokumen/lamaran/{application}', [DocumentController::class, 'upload'])->name('documents.upload');
    Route::patch('dokumen/{document}/review', [DocumentController::class, 'review'])->name('documents.review');
    Route::get('dokumen/{document}/unduh', [DocumentController::class, 'download'])->name('documents.download');
    Route::resource('wawancara', InterviewController::class)->except('destroy')->parameters(['wawancara' => 'interview'])->names('interviews');
    Route::post('wawancara/{interview}/pertanyaan', [InterviewController::class, 'addQuestion'])->name('interviews.questions');
    Route::put('wawancara/{interview}/jawaban', [InterviewController::class, 'saveAnswers'])->name('interviews.answers');
    Route::resource('jadwal-penerbangan', FlightController::class)->parameters(['jadwal-penerbangan' => 'flight'])->names('flights');
    foreach (['OPP' => ['opp', 'opp'], 'departure' => ['keberangkatan', 'departures'], 'arrival' => ['kedatangan', 'arrivals']] as $type => [$path, $prefix]) {
        foreach (['index' => ['GET', ''], 'create' => ['GET', '/create'], 'store' => ['POST', ''], 'edit' => ['GET', '/{event}/edit'], 'update' => ['PUT', '/{event}']] as $action => [$verb, $suffix]) {
            Route::match([$verb], $path.$suffix, [TravelEventController::class, $action])->defaults('event_type', $type)->whereNumber('event')->name($prefix.'.'.$action);
        }
    }

    Route::get('kesiapan-keberangkatan', [ReadinessController::class, 'index'])->name('readiness.index');
    Route::get('kesiapan-keberangkatan/{application}', [ReadinessController::class, 'show'])->name('readiness.show');
    Route::post('kesiapan-keberangkatan/{application}', [ReadinessController::class, 'store'])->name('readiness.store');
    Route::put('kesiapan-keberangkatan/{application}/{checklist}', [ReadinessController::class, 'update'])->name('readiness.update');
    Route::get('izin-pelatihan', [TrainingActivityController::class, 'leaves'])->name('training-leaves.index');
    Route::post('izin-pelatihan', [TrainingActivityController::class, 'storeLeave'])->name('training-leaves.store');
    Route::patch('izin-pelatihan/{leave}', [TrainingActivityController::class, 'action'])->whereNumber('leave')->name('training-leaves.action');
    Route::get('izin-pelatihan/{leave}/bukti', [TrainingActivityController::class, 'evidence'])->whereNumber('leave')->name('training-leaves.evidence');
    Route::get('perkembangan-pelatihan', [TrainingActivityController::class, 'progress'])->name('training-progress.index');

    Route::resource('pelatihan/angkatan', TrainingBatchController::class)->except('destroy')->parameters(['angkatan' => 'batch'])->names('training-batches');
    Route::resource('pelatihan', TrainingEnrollmentController::class)->only(['index', 'create', 'store', 'edit', 'update'])->parameters(['pelatihan' => 'enrollment'])->names('training');
    Route::resource('admin/negara', CountryController::class)->only(['index', 'create', 'store', 'edit', 'update'])->parameters(['negara' => 'country'])->names('countries');
    Route::patch('admin/negara/{country}/status', [CountryController::class, 'status'])->name('countries.status');
    Route::get('data-master/alur-versi', [MasterWorkflowController::class, 'index'])->name('master-workflows.index');
    Route::post('data-master/alur-versi', [MasterWorkflowController::class, 'save'])->name('master-workflows.save');
    Route::post('data-master/alur-versi/terbit', [MasterWorkflowController::class, 'publish'])->name('master-workflows.publish');
    Route::post('data-master/alur-versi/pakai', [MasterWorkflowController::class, 'assign'])->name('master-workflows.assign');
    Route::post('data-master/referensi/{reference}', [MasterDataController::class, 'saveReference'])->name('master-reference.save');
    Route::get('data-master/referensi/{reference}', [MasterDataController::class, 'reference'])->name('master-reference.index');
    Route::get('data-master/{master}', [MasterDataController::class, 'index'])->name('master-data.index');
    Route::get('data-master/{master}/create', [MasterDataController::class, 'create'])->name('master-data.create');
    Route::post('data-master/{master}', [MasterDataController::class, 'store'])->name('master-data.store');
    Route::get('data-master/{master}/{record}/edit', [MasterDataController::class, 'edit'])->whereNumber('record')->name('master-data.edit');
    Route::put('data-master/{master}/{record}', [MasterDataController::class, 'update'])->whereNumber('record')->name('master-data.update');
    Route::get('protokol', [\App\Http\Controllers\TrustCommitmentController::class, 'index'])->name('protocol.index');
    Route::get('protokol/lamaran/{application}', [\App\Http\Controllers\TrustCommitmentController::class, 'show'])->name('protocol.show');
    Route::post('protokol/lamaran/{application}', [\App\Http\Controllers\TrustCommitmentController::class, 'action'])->name('protocol.action');
    Route::post('protokol/pengaturan', [\App\Http\Controllers\TrustCommitmentController::class, 'settings'])->name('protocol.settings');
    Route::post('protokol/materi', [\App\Http\Controllers\TrustCommitmentController::class, 'content'])->name('protocol.content');
    Route::post('protokol/routing', [\App\Http\Controllers\TrustCommitmentController::class, 'routing'])->name('protocol.routing');

    Route::get('jm-care', [CareJourneyController::class, 'index'])->name('care.index');
    Route::get('jm-care/cpmi/{candidate}', [CareJourneyController::class, 'show'])->name('care.show');
    foreach (['touchpoint', 'issue', 'support', 'decision'] as $careAction) {
        Route::post('jm-care/cpmi/{candidate}/'.$careAction, [CareJourneyController::class, $careAction])->name('care.'.$careAction);
    }
    Route::post('jm-care/cpmi/{candidate}/issues/{issue}/status', [CareJourneyController::class, 'issueStatus'])->whereNumber('issue')->name('care.issue-status');
    Route::post('jm-care/cpmi/{candidate}/issues/{issue}/verify', [CareJourneyController::class, 'verify'])->whereNumber('issue')->name('care.verify');
    Route::post('jm-care/cpmi/{candidate}/supports/{support}/status', [CareJourneyController::class, 'supportStatus'])->whereNumber('support')->name('care.support-status');
    Route::post('jm-care/cpmi/{candidate}/exit-review', [CareJourneyController::class, 'exitReview'])->name('care.exit-review');
    Route::post('jm-care/cpmi/{candidate}/documents/{document}/checks', [CareJourneyController::class, 'documentChecks'])->name('care.document-checks');
    Route::post('jm-care/templates/{country}', [CareJourneyController::class, 'template'])->name('care.template');

    Route::get('tindak-lanjut', [FollowUpWorkspaceController::class, 'index'])->name('follow-ups.index');
    Route::get('api/crm/follow-ups', [FollowUpWorkspaceController::class, 'index'])->name('follow-ups.api-index');
    Route::post('tindak-lanjut/lamaran/{application}', [FollowUpWorkspaceController::class, 'action'])->name('follow-ups.action');
    Route::resource('tindak-lanjut', TaskController::class)->only(['create', 'store', 'edit', 'update'])->parameters(['tindak-lanjut' => 'task'])->names('follow-ups');
    Route::patch('tindak-lanjut/{task}/selesai', [TaskController::class, 'complete'])->name('follow-ups.complete');
});

Route::view('privacy', 'portal.privacy')->name('privacy');
Route::get('apply/{slug}', [FunnelPublicController::class, 'show'])->middleware('throttle:60,1')->name('apply.show');
Route::post('apply/{slug}', [FunnelPublicController::class, 'store'])->middleware('throttle:10,1')->block(10, 10)->name('apply.submit');
Route::get('apply/{slug}/thank-you/{token}', [FunnelPublicController::class, 'thankYou'])->name('apply.thank-you');
Route::post('apply/{slug}/whatsapp/{token}', [FunnelPublicController::class, 'whatsapp'])->middleware('throttle:10,1')->name('apply.whatsapp');
Route::post('apply/{slug}/events', [FunnelPublicController::class, 'event'])->middleware('throttle:30,1')->name('apply.event');
Route::post('api/auth/cpmi/login', [PortalController::class, 'authenticate'])->name('auth.cpmi.login');
Route::get('portal/login', [PortalController::class, 'login'])->name('portal.login');
Route::post('portal/login', [PortalController::class, 'authenticate'])->name('portal.login.store');
Route::get('portal/invitation/{token}', [PortalController::class, 'invitation'])->middleware('throttle:10,1')->name('portal.invitation');
Route::post('portal/invitation/{token}', [PortalController::class, 'activate'])->middleware('throttle:5,1')->block(10, 10)->name('portal.activate');
Route::middleware(PortalAccess::class)->group(function (): void {
    Route::get('portal', [PortalController::class, 'page'])->name('portal.home');
    Route::match(['get', 'post'], 'portal/kabar', [CareJourneyController::class, 'portalWelfare'])->middleware('throttle:10,1')->name('portal.welfare');
    foreach (['process', 'documents', 'schedules', 'requests', 'help', 'profile', 'privacy', 'notifications'] as $page) {
        Route::get('portal/'.$page, [PortalController::class, 'page'])->defaults('page', $page)->name('portal.'.$page);
    }
    Route::post('portal/documents/{requirement}/upload', [PortalController::class, 'upload'])->where('requirement', '(?:(?:rule|care)-)?[0-9]+')->middleware('throttle:15,1')->name('portal.upload');
    Route::get('portal/documents/{document}/file', [PortalController::class, 'file'])->name('portal.document.file');
    Route::post('portal/requests', [PortalController::class, 'requestHelp'])->middleware('throttle:10,1')->name('portal.requests.store');
    Route::post('portal/profile', [PortalController::class, 'profileUpdate'])->middleware('throttle:10,1')->name('portal.profile.update');
    Route::post('portal/notifications/{notification}/read', [PortalController::class, 'read'])->whereNumber('notification')->name('portal.notifications.read');
    Route::post('portal/contact-cs', [PortalController::class, 'contact'])->middleware('throttle:15,1')->name('portal.contact');
    Route::post('portal/preferences', [PortalController::class, 'preferences'])->name('portal.preferences');
    Route::post('portal/revoke-sessions', [PortalController::class, 'revokeSessions'])->name('portal.revokeSessions');
    Route::post('portal/logout', [PortalController::class, 'logout'])->name('portal.logout');
    foreach (['me', 'summary', 'process', 'documents', 'schedules', 'requests', 'notifications', 'profile'] as $page) {
        Route::get('api/portal/me'.($page === 'me' ? '' : '/'.$page), [PortalController::class, 'page'])->defaults('page', $page)->name('portal.api.'.$page);
    }
    Route::post('api/portal/me/documents/{requirement}/upload', [PortalController::class, 'upload'])->middleware('throttle:15,1')->name('portal.api.upload');
    Route::post('api/portal/me/requests', [PortalController::class, 'requestHelp'])->middleware('throttle:10,1')->name('portal.api.request');
    Route::patch('api/portal/me/profile', [PortalController::class, 'profileUpdate'])->name('portal.api.profile.update');
    Route::patch('api/portal/me/notifications/{notification}/read', [PortalController::class, 'read'])->name('portal.api.read');
    Route::post('api/portal/me/contact-cs', [PortalController::class, 'contact'])->name('portal.api.contact');
});

Route::get('lowongan', [PublicWebsiteController::class, 'listing'])->name('public.vacancies');
Route::get('lowongan/{slug}', [PublicWebsiteController::class, 'detail'])->name('public.vacancy');
Route::get('negara/{slug}', [PublicWebsiteController::class, 'country'])->name('public.country');
Route::get('site-media/{asset}', [PublicWebsiteController::class, 'media'])->whereNumber('asset')->name('site.media');
Route::get('sitemap.xml', [PublicWebsiteController::class, 'sitemap'])->name('public.sitemap');
Route::get('sitemap-pages.xml', [PublicWebsiteController::class, 'sitemap'])->defaults('kind', 'pages')->name('public.sitemap.pages');
Route::get('sitemap-lowongan.xml', [PublicWebsiteController::class, 'sitemap'])->defaults('kind', 'vacancies')->name('public.sitemap.vacancies');
Route::get('robots.txt', [PublicWebsiteController::class, 'robots'])->name('public.robots');
Route::post('hubungi-kami/whatsapp', [PublicWebsiteController::class, 'contactWhatsapp'])->middleware('throttle:10,1')->name('public.contact.whatsapp');
Route::post('hubungi-kami', [PublicWebsiteController::class, 'contact'])->middleware('throttle:5,1')->block(10, 10)->name('public.contact');
Route::post('api/public/events', [PublicWebsiteController::class, 'event'])->middleware('throttle:60,1')->name('public.events');
Route::get('api/public/vacancies', [PublicWebsiteController::class, 'listing'])->name('public-api.vacancies');
Route::get('api/public/vacancies/{slug}', [PublicWebsiteController::class, 'detail'])->name('public-api.vacancy');
Route::get('api/public/countries', [PublicWebsiteController::class, 'countries'])->name('public-api.countries');
Route::get('api/public/countries/{slug}', [PublicWebsiteController::class, 'country'])->name('public-api.country');
Route::get('api/public/guides', [PublicWebsiteController::class, 'guides'])->name('public-api.guides');
Route::get('api/public/guides/{slug}', [PublicWebsiteController::class, 'page'])->name('public-api.guide');
Route::get('api/public/site/navigation', [PublicWebsiteController::class, 'navigation'])->name('public-api.navigation');
Route::get('api/public/site/pages/{slug}', [PublicWebsiteController::class, 'page'])->name('public-api.page');
Route::get('panduan/{slug}', [PublicWebsiteController::class, 'page'])->name('public.guide');
Route::get('{slug}', [PublicWebsiteController::class, 'page'])->where('slug', '(?!(?:register|logout)$)[a-z0-9]+(?:-[a-z0-9]+)*')->name('public.page');

Route::get('api/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:60,1')->name('whatsapp.verify');
Route::post('api/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1')->name('whatsapp.receive');

Route::post('api/webhooks/whatsapp-qr', [WhatsAppQrController::class, 'webhook'])->middleware('throttle:300,1')->name('whatsapp-qr.webhook');
