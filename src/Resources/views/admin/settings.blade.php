<x-admin::layouts>
    <x-slot:title>@lang('helloasso::app.admin.title')</x-slot>

    <div class="flex items-center justify-between gap-4">
        <h1 class="text-xl font-bold text-gray-800 dark:text-white">@lang('helloasso::app.admin.title')</h1>

        <form method="GET" action="{{ route('admin.helloasso.settings') }}">
            <select name="channel" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" onchange="this.form.submit()">
                @foreach ($channels as $channel)
                    <option value="{{ $channel->code }}" @selected($selectedChannel === $channel->code)>{{ $channel->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="mt-5 grid gap-5">
        @if ($errors->any())
            <div class="rounded border border-red-300 bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if (session('success'))
            <div class="rounded border border-green-300 bg-green-50 p-4 text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('success') }}</div>
        @endif

        @php($connection = $connections->get($selectedChannel))

        <form method="POST" action="{{ route('admin.helloasso.save') }}" class="grid gap-5 rounded bg-white p-6 shadow dark:bg-gray-900">
            @csrf
            <input type="hidden" name="channel_code" value="{{ $selectedChannel }}">

            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $connection?->enabled))>
                    @lang('helloasso::app.admin.enabled')
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.environment')
                    <select name="environment" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950">
                        <option value="sandbox" @selected(old('environment', $connection?->environment ?? 'sandbox') === 'sandbox')>@lang('helloasso::app.admin.sandbox')</option>
                        <option value="production" @selected(old('environment', $connection?->environment) === 'production')>@lang('helloasso::app.admin.production')</option>
                    </select>
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.authentication')
                    <select name="auth_mode" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950">
                        <option value="association" @selected(old('auth_mode', $connection?->auth_mode ?? 'association') === 'association')>@lang('helloasso::app.admin.association-key')</option>
                        <option value="partner" @selected(old('auth_mode', $connection?->auth_mode) === 'partner')>@lang('helloasso::app.admin.partner-oauth')</option>
                    </select>
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.organization-slug')
                    <input name="organization_slug" value="{{ old('organization_slug', $connection?->organization_slug) }}" maxlength="150" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950">
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.client-id')
                    <input name="client_id" value="{{ old('client_id', $connection?->client_id) }}" required maxlength="200" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950">
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.client-secret')
                    <input name="client_secret" type="password" autocomplete="new-password" maxlength="500" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950" placeholder="{{ $connection?->client_secret ? trans('helloasso::app.admin.secret-saved') : '' }}">
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.webhook-signature')
                    <input name="webhook_signature_key" type="password" autocomplete="new-password" maxlength="500" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950" placeholder="{{ $connection?->webhook_signature_key ? trans('helloasso::app.admin.secret-saved') : trans('helloasso::app.admin.partner-only') }}">
                </label>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="enable_sepa" value="1" @checked(old('enable_sepa', $connection?->enable_sepa))>
                    @lang('helloasso::app.admin.enable-sepa')
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.initial-percent')
                    <input type="number" name="initial_percent" min="1" max="99" value="{{ old('initial_percent', $connection?->initial_percent ?? 50) }}" class="w-32 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950">
                </label>

                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">@lang('helloasso::app.admin.installments')</legend>
                    <div class="flex flex-wrap gap-3">
                        @foreach (range(2, 12) as $count)
                            <label class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" name="installment_counts[]" value="{{ $count }}" @checked(in_array($count, old('installment_counts', $connection?->installment_counts ?? [])))>
                                {{ $count }}×
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    @lang('helloasso::app.admin.order-policy')
                    <select name="order_policy" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-950">
                        <option value="first_authorized" @selected(old('order_policy', $connection?->order_policy ?? 'first_authorized') === 'first_authorized')>@lang('helloasso::app.admin.first-authorized')</option>
                        <option value="full_amount" @selected(old('order_policy', $connection?->order_policy) === 'full_amount')>@lang('helloasso::app.admin.full-amount')</option>
                        <option value="verified_return" @selected(old('order_policy', $connection?->order_policy) === 'verified_return')>@lang('helloasso::app.admin.verified-return')</option>
                    </select>
                </label>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="cancel_future_terms_on_full_refund" value="1" @checked(old('cancel_future_terms_on_full_refund', $connection?->cancel_future_terms_on_full_refund ?? true))>
                @lang('helloasso::app.admin.cancel-future-terms')
            </label>

            <div class="flex flex-wrap gap-3">
                <button class="primary-button px-5 py-2" type="submit">@lang('helloasso::app.admin.save')</button>
                @if ($connection)
                    <button class="secondary-button px-5 py-2" type="submit" form="helloasso-test-form">@lang('helloasso::app.admin.test-connection')</button>
                    @if ($connection->auth_mode === 'partner' && $connection->client_secret)
                        <a class="secondary-button px-5 py-2" href="{{ route('admin.helloasso.connect', $connection->channel_code) }}">@lang('helloasso::app.admin.connect-organization')</a>
                    @endif
                @endif
            </div>
        </form>

        @if ($connection)
            <form id="helloasso-test-form" method="POST" action="{{ route('admin.helloasso.test', $connection->channel_code) }}">@csrf</form>
            <div class="rounded bg-white p-5 text-sm text-gray-600 shadow dark:bg-gray-900 dark:text-gray-300">
                <p>@lang('helloasso::app.admin.webhook-url') <code>{{ route('helloasso.payment.webhook', $connection->channel_code) }}</code></p>
                <p class="mt-2">@lang('helloasso::app.admin.production-disabled-info')</p>
            </div>
        @endif
    </div>
</x-admin::layouts>
