<x-admin::layouts>
    <x-slot:title>@lang('helloasso::app.admin.title')</x-slot>

    @php
        $connection = $connections->get($selectedChannel);
        $paymentSettingsUrl = route('admin.configuration.index', [
            'slug'   => 'sales',
            'slug2'  => 'payment_methods',
            'channel' => $selectedChannel,
        ]);
    @endphp

    <div class="flex flex-col gap-6">
        <header class="flex flex-wrap items-center justify-between gap-5 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex min-w-0 items-center gap-4">
                <span class="icon-payment flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-2xl text-violet-700 dark:bg-violet-950 dark:text-violet-300"></span>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-violet-700 dark:text-violet-300">HelloAsso</p>
                    <h1 class="mt-1 text-xl font-bold text-gray-900 dark:text-white">@lang('helloasso::app.admin.title')</h1>
                </div>
            </div>

            <form method="GET" action="{{ route('admin.helloasso.settings') }}" class="flex items-center gap-3">
                <label for="helloasso-channel" class="text-sm font-medium text-gray-600 dark:text-gray-300">
                    @lang('admin::app.cms.index.channel')
                </label>

                <select
                    id="helloasso-channel"
                    name="channel"
                    class="custom-select min-w-48 rounded-md border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                    onchange="this.form.submit()"
                >
                    @foreach ($channels as $channel)
                        <option value="{{ $channel->code }}" @selected($selectedChannel === $channel->code)>
                            {{ $channel->name }}
                        </option>
                    @endforeach
                </select>
            </form>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if (session('success'))
            <div role="status" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                {{ session('success') }}
            </div>
        @endif

        <a
            href="{{ $paymentSettingsUrl }}"
            class="flex flex-col gap-3 rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm text-blue-900 transition hover:border-blue-300 hover:bg-blue-100 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100 dark:hover:bg-blue-900 sm:flex-row sm:items-center sm:justify-between"
        >
            <span class="flex items-start gap-3">
                <span class="icon-info mt-0.5 shrink-0 text-lg"></span>
                <span>@lang('helloasso::app.admin.activation-location')</span>
            </span>

            <span class="inline-flex shrink-0 items-center gap-2 font-semibold">
                @lang('admin::app.configuration.index.sales.payment-methods.page-title')
                <span class="icon-arrow-right"></span>
            </span>
        </a>

        @if ($connection)
            <section class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">@lang('helloasso::app.admin.environment')</p>
                    <p class="mt-2 inline-flex rounded-full px-3 py-1 text-sm font-semibold {{ $connection->isSandbox() ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' : 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200' }}">
                        @lang($connection->isSandbox() ? 'helloasso::app.admin.sandbox' : 'helloasso::app.admin.production')
                    </p>

                    @if (! $connection->isSandbox())
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">@lang('helloasso::app.admin.production-disabled-info')</p>
                    @endif

                    @if (! config('helloasso.allow_production', true))
                        <p class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            @lang('helloasso::app.admin.production-blocked-by-environment')
                        </p>
                    @endif
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">@lang('helloasso::app.admin.cash-in-status')</p>
                    <p class="mt-2 text-base font-semibold text-gray-900 dark:text-white">
                        @if ($connection->cash_in_eligible === true)
                            @lang('helloasso::app.admin.cash-in-eligible')
                        @elseif ($connection->cash_in_eligible === false)
                            @lang('helloasso::app.admin.cash-in-ineligible')
                        @else
                            @lang('helloasso::app.admin.cash-in-unknown')
                        @endif
                    </p>

                    @if ($connection->cash_in_checked_at)
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            @lang('helloasso::app.admin.cash-in-checked', ['date' => $connection->cash_in_checked_at->format('d/m/Y H:i')])
                        </p>
                    @endif
                </div>
            </section>
        @endif

        @if ($connection)
            <form id="helloasso-test-form" method="POST" action="{{ route('admin.helloasso.test', $connection->channel_code) }}">
                @csrf
            </form>
        @endif

        <form method="POST" action="{{ route('admin.helloasso.save') }}" class="flex flex-col gap-5">
            @csrf
            <input type="hidden" name="channel_code" value="{{ $selectedChannel }}">

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-6 border-b border-gray-100 pb-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">@lang('helloasso::app.admin.authentication')</h2>
                </div>

                <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                    <div>
                        <label for="helloasso-environment" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">@lang('helloasso::app.admin.environment')</label>
                        <select id="helloasso-environment" name="environment" required class="custom-select w-full rounded-md border-gray-300 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="sandbox" @selected(old('environment', $connection?->environment ?? 'sandbox') === 'sandbox')>@lang('helloasso::app.admin.sandbox')</option>
                            <option value="production" @selected(old('environment', $connection?->environment) === 'production')>@lang('helloasso::app.admin.production')</option>
                        </select>
                    </div>

                    <div>
                        <label for="helloasso-auth-mode" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">@lang('helloasso::app.admin.authentication')</label>
                        <select id="helloasso-auth-mode" name="auth_mode" required class="custom-select w-full rounded-md border-gray-300 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="association" @selected(old('auth_mode', $connection?->auth_mode ?? 'association') === 'association')>@lang('helloasso::app.admin.association-key')</option>
                            <option value="partner" @selected(old('auth_mode', $connection?->auth_mode) === 'partner')>@lang('helloasso::app.admin.partner-oauth')</option>
                        </select>
                    </div>

                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.label for="helloasso-organization-slug">
                            @lang('helloasso::app.admin.organization-slug')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="helloasso-organization-slug"
                            name="organization_slug"
                            maxlength="150"
                            :value="old('organization_slug', $connection?->organization_slug)"
                            :label="trans('helloasso::app.admin.organization-slug')"
                            :placeholder="trans('helloasso::app.admin.organization-slug')"
                        />

                        <x-admin::form.control-group.error control-name="organization_slug" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.label class="required" for="helloasso-client-id">
                            @lang('helloasso::app.admin.client-id')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="helloasso-client-id"
                            name="client_id"
                            rules="required|max:200"
                            maxlength="200"
                            :value="old('client_id', $connection?->client_id)"
                            :label="trans('helloasso::app.admin.client-id')"
                            :placeholder="trans('helloasso::app.admin.client-id')"
                        />

                        <x-admin::form.control-group.error control-name="client_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.label for="helloasso-client-secret">
                            @lang('helloasso::app.admin.client-secret')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="password"
                            id="helloasso-client-secret"
                            name="client_secret"
                            maxlength="500"
                            autocomplete="new-password"
                            :label="trans('helloasso::app.admin.client-secret')"
                            :placeholder="$connection?->client_secret ? trans('helloasso::app.admin.secret-saved') : trans('helloasso::app.admin.client-secret')"
                        />

                        <x-admin::form.control-group.error control-name="client_secret" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.label for="helloasso-webhook-key">
                            @lang('helloasso::app.admin.webhook-signature')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="password"
                            id="helloasso-webhook-key"
                            name="webhook_signature_key"
                            maxlength="500"
                            autocomplete="new-password"
                            :label="trans('helloasso::app.admin.webhook-signature')"
                            :placeholder="$connection?->webhook_signature_key ? trans('helloasso::app.admin.secret-saved') : trans('helloasso::app.admin.partner-only')"
                        />

                        <x-admin::form.control-group.error control-name="webhook_signature_key" />
                    </x-admin::form.control-group>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-6 border-b border-gray-100 pb-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">@lang('helloasso::app.admin.installments')</h2>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="flex items-start gap-3 rounded-md bg-gray-50 p-4 dark:bg-gray-950">
                        <input id="helloasso-enable-sepa" type="checkbox" name="enable_sepa" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-violet-600 focus:ring-violet-500" @checked(old('enable_sepa', $connection?->enable_sepa))>
                        <label for="helloasso-enable-sepa" class="text-sm font-medium text-gray-800 dark:text-gray-200">
                            @lang('helloasso::app.admin.enable-sepa')
                        </label>
                    </div>

                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.label class="required" for="helloasso-initial-percent">
                            @lang('helloasso::app.admin.initial-percent')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="number"
                            id="helloasso-initial-percent"
                            name="initial_percent"
                            rules="required|integer|min:1|max:99"
                            min="1"
                            max="99"
                            :value="old('initial_percent', $connection?->initial_percent ?? 50)"
                            :label="trans('helloasso::app.admin.initial-percent')"
                        />

                        <x-admin::form.control-group.error control-name="initial_percent" />
                    </x-admin::form.control-group>

                    <fieldset class="lg:col-span-2">
                        <legend class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-200">@lang('helloasso::app.admin.installments')</legend>

                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-11">
                            @foreach (range(2, 12) as $count)
                                <label class="flex cursor-pointer items-center justify-center gap-2 rounded-md border border-gray-200 bg-white px-2 py-3 text-sm font-medium text-gray-700 transition hover:border-violet-400 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                    <input type="checkbox" name="installment_counts[]" value="{{ $count }}" class="h-4 w-4 rounded border-gray-300 text-violet-600 focus:ring-violet-500" @checked(in_array($count, old('installment_counts', $connection?->installment_counts ?? [])))>
                                    <span>{{ $count }}×</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-6 border-b border-gray-100 pb-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">@lang('helloasso::app.admin.order-policy')</h2>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="helloasso-order-policy" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">@lang('helloasso::app.admin.order-policy')</label>
                        <select id="helloasso-order-policy" name="order_policy" required class="custom-select w-full rounded-md border-gray-300 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="first_authorized" @selected(old('order_policy', $connection?->order_policy ?? 'first_authorized') === 'first_authorized')>@lang('helloasso::app.admin.first-authorized')</option>
                            <option value="full_amount" @selected(old('order_policy', $connection?->order_policy) === 'full_amount')>@lang('helloasso::app.admin.full-amount')</option>
                            <option value="verified_return" @selected(old('order_policy', $connection?->order_policy) === 'verified_return')>@lang('helloasso::app.admin.verified-return')</option>
                        </select>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-md bg-gray-50 p-4 text-sm text-gray-700 dark:bg-gray-950 dark:text-gray-200">
                        <input type="checkbox" name="cancel_future_terms_on_full_refund" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-violet-600 focus:ring-violet-500" @checked(old('cancel_future_terms_on_full_refund', $connection?->cancel_future_terms_on_full_refund ?? true))>
                        <span>@lang('helloasso::app.admin.cancel-future-terms')</span>
                    </label>
                </div>
            </section>

            @if ($connection)
                <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">@lang('helloasso::app.admin.webhook-url')</h2>
                    <p class="mt-3 break-all rounded-md bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-950 dark:text-gray-200">
                        {{ route('helloasso.payment.webhook', $connection->channel_code) }}
                    </p>
                </section>
            @endif

            <footer class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <button class="primary-button px-5 py-2.5" type="submit">@lang('helloasso::app.admin.save')</button>

                <div class="flex flex-wrap gap-3">
                    @if ($connection)
                        <button class="secondary-button px-5 py-2.5" type="submit" form="helloasso-test-form">
                            @lang('helloasso::app.admin.test-connection')
                        </button>

                        @if ($connection->auth_mode === 'partner' && $connection->client_secret)
                            <a class="secondary-button px-5 py-2.5" href="{{ route('admin.helloasso.connect', $connection->channel_code) }}">
                                @lang('helloasso::app.admin.connect-organization')
                            </a>
                        @endif
                    @endif
                </div>
            </footer>
        </form>
    </div>
</x-admin::layouts>
