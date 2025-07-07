const { registerPaymentMethod } = window.wc.wcBlocksRegistry
const { getSetting } = window.wc.wcSettings
const { decodeEntities } = window.wp.htmlEntities
const { createElement } = window.wp.element

// Get settings data
const settings = getSetting('zano_payment_data', {})

// Payment method label component
const Label = (props) => {
  const { PaymentMethodLabel } = props.components
  return createElement(
    'span',
    { style: { width: '100%' } },
    createElement(PaymentMethodLabel, {
      text: decodeEntities(settings.title || ''),
    }),
    settings.icon &&
      createElement('img', {
        src: settings.icon,
        alt: 'Zano',
        style: {
          float: 'right',
          marginRight: '20px',
          height: '24px',
          width: 'auto',
        },
      })
  )
}

// Payment method content component
const Content = () => {
  return createElement('div', {
    dangerouslySetInnerHTML: {
      __html: decodeEntities(settings.description || ''),
    },
  })
}

// Register the payment method
registerPaymentMethod({
  name: 'zano_payment',
  label: createElement(Label),
  content: createElement(Content),
  edit: createElement(Content),
  canMakePayment: () => true,
  ariaLabel: decodeEntities(settings.title || 'Zano Payment'),
  supports: {
    features: settings.supports || ['products'],
  },
})
