/**
 * Zano Payment Gateway - Frontend script for payment page
 */

jQuery(document).ready(function ($) {
  // Initialize buttons
  var copyButtons = $('.zano-copy-button')
  var checkPaymentButton = $('.zano-check-payment-button, .check-payment')
  var statusIndicator = $('.zano-status-indicator')
  var confirmationProgress = $('.confirmation-progress')
  var isCheckingPayment = false
  var paymentCheckInterval = null

  // Timer functionality is now handled in the payment page template itself
  // Removed old jQuery timer code to prevent conflicts

  // Handle copy buttons
  copyButtons.on('click', function (e) {
    e.preventDefault()
    var $button = $(this)
    var textToCopy = $button.data('copy')
    var originalText = $button.text()

    // Debug logging
    console.log('Copy button clicked:', {
      text: textToCopy,
      button: $button[0],
      hasClipboardAPI: !!navigator.clipboard,
      isSecureContext: window.isSecureContext,
    })

    // Function to show success feedback
    function showSuccess() {
      $button.text('Copied!').addClass('copied')
      setTimeout(function () {
        $button.text(originalText).removeClass('copied')
      }, 1500)
    }

    // Function to show error feedback
    function showError() {
      $button.text('Failed!').addClass('error')
      setTimeout(function () {
        $button.text(originalText).removeClass('error')
      }, 1500)
    }

    // Try modern clipboard API first
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard
        .writeText(textToCopy)
        .then(function () {
          showSuccess()
        })
        .catch(function (err) {
          console.log('Clipboard API failed, trying fallback:', err)
          // Fallback to legacy method
          fallbackCopy(textToCopy, showSuccess, showError)
        })
    } else {
      // Use fallback method for older browsers or non-secure contexts
      fallbackCopy(textToCopy, showSuccess, showError)
    }
  })

  // Fallback copy method for older browsers
  function fallbackCopy(text, onSuccess, onError) {
    var textArea = document.createElement('textarea')
    textArea.value = text
    textArea.style.position = 'fixed'
    textArea.style.left = '-999999px'
    textArea.style.top = '-999999px'
    document.body.appendChild(textArea)
    textArea.focus()
    textArea.select()

    try {
      var successful = document.execCommand('copy')
      if (successful) {
        onSuccess()
      } else {
        onError()
      }
    } catch (err) {
      console.error('Fallback copy failed:', err)
      onError()
    } finally {
      document.body.removeChild(textArea)
    }
  }

  // Function to check payment status
  function checkPayment() {
    if (isCheckingPayment) {
      return
    }

    var button = checkPaymentButton.first()
    var orderId = $('.zano-payment-status').data('order-id')

    if (!orderId) {
      console.error('No order ID found')
      return
    }

    // Set checking flag
    isCheckingPayment = true

    // Update button state
    var originalText = button.text()
    button
      .text(zano_params.checking_text)
      .prop('disabled', true)
      .addClass('checking')

    // Show spinner
    if (!button.find('.checking-animation').length) {
      button.prepend('<span class="checking-animation"></span>')
    }

    // Make AJAX request to check payment
    $.ajax({
      url: zano_params.ajax_url,
      type: 'POST',
      data: {
        action: 'zano_check_payment',
        order_id: orderId,
        nonce: zano_params.nonce,
      },
      success: function (response) {
        isCheckingPayment = false

        if (response.success) {
          // Payment found
          if (response.data.status === 'confirmed') {
            statusIndicator
              .removeClass('pending detected')
              .addClass('confirmed')
            statusIndicator.html(zano_params.confirmed_text)
            button.hide() // Hide the check button

            // Clear the auto-check interval as payment is confirmed
            if (paymentCheckInterval) {
              clearInterval(paymentCheckInterval)
            }

            if (confirmationProgress.length) {
              confirmationProgress.find('.progress').css('width', '100%')
              confirmationProgress
                .find('.current-confirmations')
                .text(response.data.required_confirmations)
            }

            // Redirect after a short delay
            setTimeout(function () {
              window.location.href = response.data.redirect_url
            }, 3000)
          } else if (response.data.status === 'detected') {
            var confirmations = response.data.confirmations || 0
            var requiredConfirmations =
              response.data.required_confirmations || 10
            var confirmationText = zano_params.detected_text.replace(
              '%s',
              confirmations + '/' + requiredConfirmations
            )

            statusIndicator
              .removeClass('pending confirmed failed')
              .addClass('detected')
            statusIndicator.html(confirmationText)

            if (confirmationProgress.length) {
              var progressPercent =
                (confirmations / requiredConfirmations) * 100
              confirmationProgress.show()
              confirmationProgress
                .find('.progress')
                .css('width', progressPercent + '%')
              confirmationProgress
                .find('.current-confirmations')
                .text(confirmations)
            }

            // Reset button state
            button
              .text(originalText)
              .prop('disabled', false)
              .removeClass('checking')
            button.find('.checking-animation').remove()
          } else if (response.data.status === 'failed') {
            // Payment failed/expired
            statusIndicator
              .removeClass('pending detected confirmed')
              .addClass('failed')
            statusIndicator.html(
              response.data.message || zano_params.failed_text
            )
            button.hide() // Hide the check button

            // Clear the auto-check interval as payment failed
            if (paymentCheckInterval) {
              clearInterval(paymentCheckInterval)
            }

            // Show alert and redirect after delay
            setTimeout(function () {
              alert('Payment failed or expired. Please try again.')
              window.location.href =
                response.data.redirect_url || window.location.origin
            }, 2000)
          } else {
            // Payment not found (pending)
            statusIndicator
              .removeClass('detected confirmed failed')
              .addClass('pending')
            statusIndicator.html(zano_params.pending_text)

            // Reset button state
            button
              .text(originalText)
              .prop('disabled', false)
              .removeClass('checking')
            button.find('.checking-animation').remove()
          }
        } else {
          // Error occurred
          console.error('Error checking payment:', response.data.message)

          // Reset button state
          button
            .text(originalText)
            .prop('disabled', false)
            .removeClass('checking')
          button.find('.checking-animation').remove()
        }
      },
      error: function (xhr, status, error) {
        isCheckingPayment = false
        console.error('AJAX error:', error)

        // Reset button state
        button
          .text(originalText)
          .prop('disabled', false)
          .removeClass('checking')
        button.find('.checking-animation').remove()
      },
    })
  }

  // Handle check payment button click
  checkPaymentButton.on('click', function (e) {
    e.preventDefault()
    checkPayment()
  })

  // Check payment status automatically on page load
  if (checkPaymentButton.length > 0) {
    // Initial check after 3 seconds
    setTimeout(function () {
      checkPayment()
    }, 3000)

    // Set up automatic checking every 10 seconds
    paymentCheckInterval = setInterval(function () {
      checkPayment()
    }, 10000)
  }
})
