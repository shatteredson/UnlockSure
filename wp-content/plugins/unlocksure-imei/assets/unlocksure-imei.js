document.addEventListener('DOMContentLoaded', function() {
  var form = document.getElementById('unlocksure-imei-form');
  if (!form) return;
  var input = document.getElementById('unlocksure-imei-input');
  var submit = document.getElementById('unlocksure-imei-submit');
  var resultDiv = document.getElementById('unlocksure-imei-result');


  function showMessage(html, isError) {
    resultDiv.innerHTML = '<div class="unlocksure-message ' + (isError ? 'error' : 'info') + '">' + html + '</div>';
  }


  function prettyJSON(obj) {
    return '<pre class="unlocksure-json">' + JSON.stringify(obj, null, 2) + '</pre>';
  }


  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = '';
    var raw = input.value || '';
    var imei = raw.replace(/\D/g, '');
    if (!/^\d{15}$/.test(imei)) {
      showMessage('Please enter a valid 15-digit IMEI.', true);
      return;
    }


    submit.disabled = true;
    submit.textContent = 'Checking…';
    showMessage('Checking IMEI, please wait…');


    try {
      var resp = await fetch(unlocksureData.rest_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': unlocksureData.nonce
        },
        body: JSON.stringify({ imei: imei })
      });


      if (!resp.ok) {
        var errText = await resp.text();
        showMessage('Server error: ' + resp.status + ' — ' + errText, true);
        return;
      }


      var data = await resp.json();


      if (data.code) { // WP_Error returned
        showMessage('Error: ' + (data.message || 'Unknown error'), true);
        return;
      }


      var res = data.result || data;
      var html = '';


      if (data.simulated) {
        html += '<div class="simulated-badge">SIMULATED RESULT (no API key configured)</div>';
      }
      if (data.cached) {
        html += '<div class="cached-badge">Cached result</div>';
      }


      // Try to map common fields
      var model = res.model || res.Model || (res.response && res.response.model) || '';
      var brand = res.brand || res.Brand || '';
      var simlock = res.simlock || res.Simlock || res.sim_lock || '';
      var carrier = res.carrier || res.Carrier || res.network || '';
      var blacklist = res.blacklist || res.Blacklist || (res.blacklist_status || '');


      html += '<div class="unlocksure-result-summary">';
      if (model) html += '<div><strong>Model:</strong> ' + model + '</div>';
      if (brand) html += '<div><strong>Brand:</strong> ' + brand + '</div>';
      if (simlock) html += '<div><strong>SIM Lock:</strong> ' + simlock + '</div>';
      if (carrier) html += '<div><strong>Carrier:</strong> ' + carrier + '</div>';
      if (blacklist) html += '<div><strong>Blacklist:</strong> ' + blacklist + '</div>';
      html += '</div>';


      // If nothing mapped, show full JSON
      if (!model && !brand && !simlock && !blacklist) {
        html += '<div><strong>Result:</strong></div>' + prettyJSON(res);
      }


      showMessage(html, false);
    } catch (err) {
      showMessage('Request failed: ' + err.message, true);
    } finally {
      submit.disabled = false;
      submit.textContent = 'Check IMEI';
    }
  });
});
