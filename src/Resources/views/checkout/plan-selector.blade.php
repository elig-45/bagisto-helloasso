<div
    class="mt-3 w-[190px] rounded-lg border border-zinc-200 p-3 text-xs max-md:w-full"
    v-if="payment.method === 'helloasso'"
    v-helloasso-plan-selector
></div>

@pushOnce('scripts')
    <script type="module">
        app.directive('helloasso-plan-selector', {
            mounted(element) {
                const counts = @json(\Elig45\BagistoHelloAsso\Models\Connection::where('channel_code', core()->getCurrentChannelCode())->first()?->installment_counts ?? []);
                const url = @json(route('helloasso.payment.plan', ['channel' => core()->getCurrentChannelCode()]));
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                const paidInFull = @json(trans('helloasso::app.checkout.pay-in-full'));
                const installmentLabel = @json(trans('helloasso::app.checkout.installments', ['count' => '__COUNT__']));
                const select = document.createElement('select');
                select.className = 'w-full rounded border-zinc-300 text-xs';
                select.setAttribute('aria-label', @json(trans('helloasso::app.checkout.payment-plan')));
                select.innerHTML = `<option value="1">${paidInFull}</option>`
                    + counts.map(count => `<option value="${count}">${installmentLabel.replace('__COUNT__', count)}</option>`).join('');
                select.addEventListener('click', event => event.stopPropagation());
                select.addEventListener('change', async () => {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ installment_count: Number(select.value) }),
                    });

                    if (! response.ok) {
                        select.value = '1';
                    }
                });
                element.append(select);
                select.dispatchEvent(new Event('change'));
            },
        });
    </script>
@endPushOnce
