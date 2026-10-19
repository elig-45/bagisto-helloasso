@php
    $installmentCounts = \Elig45\BagistoHelloAsso\Models\Connection::globalConnection()?->installment_counts ?? [];
    $cart = \Webkul\Checkout\Facades\Cart::getCart();
    $selectedPlan = (int) session('helloasso.installment_count.'.($cart?->id ?? 0), 1);

    if ($selectedPlan !== 1 && ! in_array($selectedPlan, $installmentCounts, true)) {
        $selectedPlan = 1;
    }
@endphp

@if (count($installmentCounts))
    <div
        class="mt-3 w-[190px] rounded-lg border border-zinc-200 p-3 text-xs max-md:w-full"
        v-if="payment.method === 'helloasso'"
        v-helloasso-plan-selector
    ></div>

    @pushOnce('scripts')
        <script type="module">
            app.directive('helloasso-plan-selector', {
                mounted(element) {
                    const counts = @json($installmentCounts);
                    const url = @json(route('helloasso.payment.plan', ['channelCode' => core()->getCurrentChannelCode()]));
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                    const initialSelection = @json($selectedPlan);
                    const paidInFull = @json(trans('helloasso::app.checkout.pay-in-full'));
                    const installmentLabel = @json(trans('helloasso::app.checkout.installments', ['count' => '__COUNT__']));
                    const select = document.createElement('select');
                    select.className = 'w-full rounded border-zinc-300 text-xs';
                    select.setAttribute('aria-label', @json(trans('helloasso::app.checkout.payment-plan')));
                    select.innerHTML = `<option value="1">${paidInFull}</option>`
                        + counts.map(count => `<option value="${count}">${installmentLabel.replace('__COUNT__', count)}</option>`).join('');
                    select.value = String(initialSelection);
                    select.dataset.savedValue = select.value;
                    select.addEventListener('click', event => event.stopPropagation());
                    select.addEventListener('change', async () => {
                        const submitButtons = [...document.querySelectorAll('v-checkout .primary-button')];
                        submitButtons.forEach(button => button.setAttribute('disabled', 'disabled'));
                        select.disabled = true;

                        try {
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
                                throw new Error('Plan selection could not be saved.');
                            }

                            select.dataset.savedValue = select.value;
                        } catch (error) {
                            select.value = select.dataset.savedValue || '1';
                            console.error(error);
                        } finally {
                            select.disabled = false;
                            submitButtons.forEach(button => button.removeAttribute('disabled'));
                        }
                    });
                    element.append(select);
                },
            });
        </script>
    @endPushOnce
@endif
