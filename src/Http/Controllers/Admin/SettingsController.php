<?php

namespace Elig45\BagistoHelloAsso\Http\Controllers\Admin;

use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
use Elig45\BagistoHelloAsso\Services\OrganizationEligibilityService;
use Elig45\BagistoHelloAsso\Services\PartnerAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class SettingsController extends Controller
{
    public function index(): View
    {
        $channels = core()->getAllChannels();
        $selectedChannel = request()->query('channel', core()->getCurrentChannelCode());

        if (! $channels->contains('code', $selectedChannel)) {
            $selectedChannel = $channels->first()?->code;
        }

        return view('helloasso::admin.settings', [
            'channels' => $channels,
            'connections' => Connection::all()->keyBy('channel_code'),
            'selectedChannel' => $selectedChannel,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel_code' => ['required', 'string', 'max:50'],
            'environment' => ['required', 'in:sandbox,production'],
            'auth_mode' => ['required', 'in:association,partner'],
            'organization_slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9-]+$/'],
            'client_id' => ['required', 'string', 'max:200'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'webhook_signature_key' => ['nullable', 'string', 'max:500'],
            'enable_sepa' => ['sometimes', 'boolean'],
            'installment_counts' => ['sometimes', 'array'],
            'installment_counts.*' => ['integer', 'between:2,12', 'distinct'],
            'initial_percent' => ['required', 'integer', 'between:1,99'],
            'order_policy' => ['required', 'in:first_authorized,full_amount,verified_return'],
            'cancel_future_terms_on_full_refund' => ['sometimes', 'boolean'],
        ]);

        if (
            $data['auth_mode'] === 'association'
            && empty($data['organization_slug'])
        ) {
            return redirect()->back()
                ->withErrors(['organization_slug' => trans('helloasso::app.admin.organization-required')])
                ->withInput();
        }

        abort_unless(core()->getAllChannels()->contains('code', $data['channel_code']), 404);

        $connection = Connection::firstOrNew(['channel_code' => $data['channel_code']]);
        if (! $connection->exists && blank($data['client_secret'])) {
            return redirect()->back()
                ->withErrors(['client_secret' => trans('helloasso::app.admin.secret-required')])
                ->withInput();
        }
        $connectionChanged = $connection->exists && (
            $connection->environment !== $data['environment']
            || $connection->auth_mode !== $data['auth_mode']
            || $connection->client_id !== $data['client_id']
            || ($data['auth_mode'] === 'association' && $connection->organization_slug !== $data['organization_slug'])
            || filled($data['client_secret'])
        );

        if ($connectionChanged) {
            $connection->access_token = null;
            $connection->refresh_token = null;
            $connection->token_expires_at = null;
            $connection->webhook_signature_key = null;
            $connection->cash_in_eligible = null;
            $connection->cash_in_checked_at = null;
        }

        $connection->fill([
            'environment' => $data['environment'],
            'auth_mode' => $data['auth_mode'],
            'organization_slug' => $data['auth_mode'] === 'association' ? $data['organization_slug'] : $connection->organization_slug,
            'client_id' => $data['client_id'],
            'enable_sepa' => $request->boolean('enable_sepa'),
            'installment_counts' => array_values(array_map('intval', $data['installment_counts'] ?? [])),
            'initial_percent' => (int) $data['initial_percent'],
            'order_policy' => $data['order_policy'],
            'cancel_future_terms_on_full_refund' => $request->boolean('cancel_future_terms_on_full_refund'),
        ]);

        if (filled($data['client_secret'])) {
            $connection->client_secret = $data['client_secret'];
        }

        if (filled($data['webhook_signature_key'])) {
            $connection->webhook_signature_key = $data['webhook_signature_key'];
        }

        $connection->save();

        return redirect()->route('admin.helloasso.settings', ['channel' => $connection->channel_code])
            ->with('success', trans('helloasso::app.admin.saved'));
    }

    public function test(
        string $channel,
        HelloAssoApiClient $api,
        OrganizationEligibilityService $eligibility,
    ): RedirectResponse {
        $connection = Connection::where('channel_code', $channel)->firstOrFail();

        if (! $connection->organization_slug) {
            return redirect()->back()->withErrors([
                'connection' => trans('helloasso::app.admin.organization-required'),
            ]);
        }

        try {
            $organization = $api->get($connection, '/organizations/'.rawurlencode($connection->organization_slug));

            $cashInEligible = $eligibility->recordApiResult($connection, $organization);

            if ($cashInEligible === false) {
                return redirect()->back()->withErrors([
                    'connection' => trans('helloasso::app.admin.connection-ineligible', [
                        'url' => 'https://admin.helloasso.com/'.$connection->organization_slug.'/verification',
                    ]),
                ]);
            }

            return redirect()->back()->with('success', trans(
                $cashInEligible === true
                    ? 'helloasso::app.admin.connection-ok'
                    : 'helloasso::app.admin.connection-eligibility-unknown'
            ));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()->withErrors(['connection' => trans('helloasso::app.admin.connection-failed')]);
        }
    }

    public function connect(string $channel, PartnerAuthorizationService $oauth): RedirectResponse
    {
        $connection = Connection::where('channel_code', $channel)->firstOrFail();
        abort_unless($connection->auth_mode === 'partner', 404);

        $redirectUri = route('admin.helloasso.callback', ['channel' => $channel]);
        abort_unless(str_starts_with($redirectUri, 'https://'), 400, trans('helloasso::app.admin.https-required'));

        return redirect()->away($oauth->authorizationUrl($connection, $redirectUri));
    }

    public function callback(string $channel, Request $request, PartnerAuthorizationService $oauth): RedirectResponse
    {
        $connection = Connection::where('channel_code', $channel)->firstOrFail();
        $state = session()->pull('helloasso.oauth.'.$channel);

        if (
            ! $state
            || ! hash_equals((string) $state['state'], (string) $request->query('state'))
            || ! $request->query('code')
        ) {
            return redirect()->route('admin.helloasso.settings', ['channel' => $channel])
                ->withErrors(['connection' => trans('helloasso::app.admin.oauth-failed')]);
        }

        try {
            $oauth->exchange($connection, (string) $request->query('code'), $state['verifier'], $state['redirect_uri']);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.helloasso.settings', ['channel' => $channel])
                ->withErrors(['connection' => trans('helloasso::app.admin.oauth-failed')]);
        }

        return redirect()->route('admin.helloasso.settings', ['channel' => $channel])
            ->with('success', trans('helloasso::app.admin.organization-connected'));
    }
}
