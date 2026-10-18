<div class="mt-2 flex flex-wrap items-end gap-3 rounded border border-gray-200 p-3 dark:border-gray-800">
    <form method="POST" action="{{ route('admin.helloasso.sync', $order->id) }}">
        @csrf
        <button class="transparent-button px-2 py-1.5" type="submit">
            @lang('helloasso::app.admin.sync-payment')
        </button>
    </form>

    <form method="POST" action="{{ route('admin.helloasso.refund', $order->id) }}" class="flex flex-wrap items-end gap-2">
        @csrf
        <label class="grid gap-1 text-xs text-gray-600 dark:text-gray-300">
            @lang('helloasso::app.admin.refund-amount')
            <input class="rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" name="amount" type="number" step="0.01" min="0.01" max="{{ $order->grand_total }}">
        </label>
        <label class="grid gap-1 text-xs text-gray-600 dark:text-gray-300">
            @lang('helloasso::app.admin.refund-comment')
            <input class="rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" name="comment" maxlength="500" required>
        </label>
        <button class="primary-button px-3 py-1.5" type="submit">
            @lang('helloasso::app.admin.request-refund')
        </button>
    </form>

    @if ($attempt?->installment_count > 1 && $attempt?->remote_order_id)
        <form method="POST" action="{{ route('admin.helloasso.cancel-future-payments', $order->id) }}" onsubmit="return confirm(@js(trans('helloasso::app.admin.confirm-cancel-future-payments')))" class="ml-auto">
            @csrf
            <button class="secondary-button px-3 py-1.5" type="submit">
                @lang('helloasso::app.admin.cancel-future-payments')
            </button>
        </form>
    @endif
</div>

@if ($payments->isNotEmpty())
    <div class="mt-3 overflow-x-auto rounded border border-gray-200 dark:border-gray-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                <tr>
                    <th class="px-3 py-2">@lang('helloasso::app.admin.installment')</th>
                    <th class="px-3 py-2">@lang('helloasso::app.admin.amount')</th>
                    <th class="px-3 py-2">@lang('helloasso::app.admin.payment-means')</th>
                    <th class="px-3 py-2">@lang('helloasso::app.admin.payment-state')</th>
                    <th class="px-3 py-2">@lang('helloasso::app.admin.refunded')</th>
                    <th class="px-3 py-2">@lang('helloasso::app.admin.receipt')</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($payments as $payment)
                    @php($receiptUrl = data_get($payment->remote_data, 'paymentReceiptUrl'))
                    @php($receiptHost = is_string($receiptUrl) ? parse_url($receiptUrl, PHP_URL_HOST) : null)
                    <tr>
                        <td class="px-3 py-2">{{ $payment->installment_number ?? '—' }}</td>
                        <td class="px-3 py-2">{{ number_format($payment->amount / 100, 2, ',', ' ') }} €</td>
                        <td class="px-3 py-2">{{ $payment->payment_means ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $payment->state }}</td>
                        <td class="px-3 py-2">{{ number_format($payment->refunded_amount / 100, 2, ',', ' ') }} €</td>
                        <td class="px-3 py-2">
                            @if (is_string($receiptUrl) && parse_url($receiptUrl, PHP_URL_SCHEME) === 'https' && is_string($receiptHost) && ($receiptHost === 'helloasso.com' || str_ends_with($receiptHost, '.helloasso.com') || $receiptHost === 'helloasso-sandbox.com' || str_ends_with($receiptHost, '.helloasso-sandbox.com')))
                                <a class="text-blue-600 underline" href="{{ $receiptUrl }}" target="_blank" rel="noopener noreferrer">@lang('helloasso::app.admin.view-receipt')</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
