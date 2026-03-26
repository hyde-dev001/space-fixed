// Create PayMongo Checkout Session
app.post('/api/checkout', async (req, res) => {
    try {
        const { amount, subtotal_amount, shipping_fee, description } = req.body;
        const normalizedSubtotal = Number(subtotal_amount ?? 0);
        const normalizedShipping = Number(shipping_fee ?? 0);
        const computedAmount = Number.isFinite(normalizedSubtotal)
            ? Math.max(0, normalizedSubtotal) + (Number.isFinite(normalizedShipping) ? Math.max(0, normalizedShipping) : 0)
            : Number(amount);

        const payableAmount = Number.isFinite(computedAmount) && computedAmount > 0
            ? computedAmount
            : Number(amount);

        if (!payableAmount || payableAmount <= 0) {
            return res.status(400).json({ error: 'Invalid amount' });
        }

        const payload = {
            data: {
                attributes: {
                    amount: Math.round(payableAmount * 100),
                    currency: 'PHP',
                    description: description || 'Payment',
                    payment_method_types: [
                        'gcash',
                        'paymaya',
                        'card'
                    ],
                    success_url: 'http://127.0.0.1:8000/checkout',
                    cancel_url: 'http://127.0.0.1:8000/checkout',
                }
            }
        };

        const response = await axios.post(
            'https://api.paymongo.com/v1/checkout_sessions',
            payload,
            {
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Basic ${Buffer.from(
                        process.env.PAYMONGO_SECRET_KEY + ':'
                    ).toString('base64')}`,
                },
            }
        );

        res.json(response.data);
    } catch (err) {
        res.status(err.response?.status || 500).json(
            err.response?.data || { error: 'Checkout error' }
        );
    }
});
