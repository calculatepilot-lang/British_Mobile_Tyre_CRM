(function () {
  'use strict';
  if (!window.BMTCRM || !BMTCRM.endpoint || !BMTCRM.nonce) return;

  function attribution() {
    try { return JSON.parse(localStorage.getItem('bmt_attribution') || '{}'); }
    catch (e) { return {}; }
  }

  function value(form, names) {
    for (var i = 0; i < names.length; i++) {
      var el = form.querySelector('[name="' + names[i] + '"]');
      if (el && el.value) return el.value.trim();
    }
    return '';
  }

  function transmit(form) {
    var payload = {
      lead: {
        lead_type: 'form',
        source: 'website-form',
        customer_name: value(form, ['customer_name','name','full_name']),
        customer_phone: value(form, ['customer_phone','phone','telephone','contact_number']),
        customer_email: value(form, ['customer_email','email']),
        service_requested: value(form, ['service_requested','service','tyresize','tyre_size']),
        city: value(form, ['city','town']),
        postcode: value(form, ['postcode','post_code','postal_code']),
        tyresize: value(form, ['tyresize','tyre_size','tyre-size']),
        vehicle_registration: value(form, ['vehicle_registration','registration','vehicle_reg','reg'])
      },
      attribution: attribution()
    };

    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon(BMTCRM.endpoint, blob);
      }
    } catch (e) {}

    try {
      fetch(BMTCRM.endpoint, {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: {'Content-Type':'application/json','X-WP-Nonce':BMTCRM.nonce}, body: body
      }).catch(function () {});
    } catch (e) {}
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches('form')) return;
    if (form.dataset.bmtCrmSent === '1') return;
    var postcode = value(form, ['postcode','post_code','postal_code']);
    var tyre = value(form, ['tyresize','tyre_size','tyre-size']);
    if (!postcode && !tyre) return;
    form.dataset.bmtCrmSent = '1';
    transmit(form);
  }, true);
})();
