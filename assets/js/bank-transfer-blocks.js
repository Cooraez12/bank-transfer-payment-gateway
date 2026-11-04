(function () {
    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML, useState } = window.wp?.element || {};

    if (typeof registerPaymentMethod !== 'function') {
        return;
    }

    const settings =
        window.wc?.wcSettings?.getPaymentMethodData?.('bank_transfer') || {};

    const label = settings.title || 'Bank Transfer';
    const description = settings.description || '';

    const BankTransferContent = () => {
        const [consented, setConsented] = useState ? useState(false) : [false, () => {}];
        const showConsent = Boolean(settings.show_consent_checkbox);
        const consentText = settings.consent_checkbox_text || 'I consent to the collection of my data to process this payment';

        const children = [];

        if (description) {
            children.push(createElement(RawHTML, { key: 'desc' }, description));
        }

        if (showConsent) {
            children.push(
                createElement(
                    'div',
                    { key: 'consent-wrap', className: 'bank-transfer-consent-wrapper', style: { marginTop: '12px' } },
                    createElement(
                        'label',
                        { style: { display: 'flex', alignItems: 'center', cursor: 'pointer', gap: '8px', fontSize: '14px' } },
                        createElement('input', {
                            type: 'checkbox',
                            id: 'bank_transfer_consent',
                            name: 'bank_transfer_consent',
                            checked: consented,
                            onChange: (e) => setConsented(e.target.checked)
                        }),
                        createElement('span', {}, consentText)
                    )
                )
            );
        }

        return createElement(
            'div',
            { className: 'bank-transfer-description' },
            children
        );
    };

    const methodConfig = {
        name: settings.id || 'bank_transfer',
        label,
        ariaLabel: label,

        content: createElement(BankTransferContent),

        edit: createElement(
            'div',
            { className: 'bank-transfer-edit' },
            label
        ),

        canMakePayment: async () => {
            return settings.can_pay !== false;
        },

        supports: {
            features: settings.supports || ['products'],
        },
    };
    if(settings.title && settings.isActive){
        registerPaymentMethod(methodConfig);
    }
    
})();


// Call this function whenever you want to refresh payment methods in the block checkout
function refreshBlockPaymentMethods() {
    if (window.wc && window.wc.blocksCheckout) {
        // For WC Blocks 8.x+ (newer API)
        document.body.dispatchEvent(new CustomEvent('wc-blocks_checkout_update_payment_methods'));
    } else {
        // Fallback for older versions
        $(document.body).trigger('update_checkout');
    }
}