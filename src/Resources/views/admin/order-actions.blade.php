<div class="mt-2 flex flex-wrap items-end gap-3 rounded border border-gray-200 p-3 dark:border-gray-800">
    <form method="POST" action="{{ route('admin.helloasso.sync', $order->id) }}">
        @csrf
        <button class="transparent-button px-2 py-1.5" type="submit">
            @lang('helloasso::app.admin.sync-payment')
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

<script>
    (() => {
        const nativeRefundAction = @js(route('admin.sales.refunds.store', $order->id));
        const remoteRefundAction = @js(route('admin.helloasso.refund', $order->id));
        const commentLabel = @js(trans('helloasso::app.admin.refund-comment'));
        const commentHelp = @js(trans('helloasso::app.admin.refund-comment-help'));
        const submitLabel = @js(trans('helloasso::app.admin.request-refund'));

        const ensureHelloAssoComment = (form) => {
            const body = form.querySelector('[name="refund[adjustment_fee]"]')?.closest('.grid.p-4');

            if (!body || body.querySelector('[data-helloasso-refund-comment]')) {
                return;
            }

            const field = document.createElement('div');
            field.className = 'mt-4 rounded border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/40';

            const label = document.createElement('label');
            label.className = 'grid gap-1.5 text-sm font-medium text-gray-800 dark:text-gray-100';
            label.textContent = commentLabel;

            const textarea = document.createElement('textarea');
            textarea.className = 'rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white';
            textarea.rows = 2;
            textarea.maxLength = 500;
            textarea.required = true;
            textarea.dataset.helloassoRefundComment = '';
            textarea.setAttribute('aria-label', commentLabel);

            const help = document.createElement('p');
            help.className = 'mt-1 text-xs font-normal text-gray-600 dark:text-gray-300';
            help.textContent = commentHelp;

            label.append(textarea);
            field.append(label, help);
            body.append(field);

            const submit = form.querySelector('button[type="submit"]');

            if (submit) {
                submit.textContent = submitLabel;
            }
        };

        const findNativeRefundForm = () => Array.from(document.forms)
            .find((form) => form.getAttribute('action') === nativeRefundAction);

        const observer = new MutationObserver(() => {
            const form = findNativeRefundForm();

            if (form) {
                ensureHelloAssoComment(form);
            }
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });

        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || form.getAttribute('action') !== nativeRefundAction) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            ensureHelloAssoComment(form);

            const comment = form.querySelector('[data-helloasso-refund-comment]')?.value.trim();

            if (!comment) {
                form.querySelector('[data-helloasso-refund-comment]')?.focus();

                return;
            }

            const remoteForm = document.createElement('form');
            remoteForm.method = 'POST';
            remoteForm.action = remoteRefundAction;
            remoteForm.hidden = true;

            for (const [name, value] of new FormData(form)) {
                if (name === '_token' || name.startsWith('refund[')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    remoteForm.append(input);
                }
            }

            const commentInput = document.createElement('input');
            commentInput.type = 'hidden';
            commentInput.name = 'comment';
            commentInput.value = comment;
            remoteForm.append(commentInput);
            document.body.append(remoteForm);
            remoteForm.submit();
        }, true);
    })();
</script>

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
