// Client: inmate-details.js
// Attach this script on pages that render .view-btn buttons (e.g. index.php).
// It will POST dkt/le to fetch_inmate_details.php and populate the modal fields.

(function () {
    // Wait for DOM
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function () {
        const modalOverlay = document.getElementById('modalOverlay');
        const modalClose = document.getElementById('modalClose');
        const modalTitle = document.getElementById('modalTitle');
        const modalSubTitle = document.getElementById('modalSubTitle');

        function openModal() {
            modalOverlay.style.display = 'flex';
            modalOverlay.setAttribute('aria-hidden', 'false');
            modalClose.focus();
        }
        function closeModal() {
            modalOverlay.style.display = 'none';
            modalOverlay.setAttribute('aria-hidden', 'true');
        }

        modalClose && modalClose.addEventListener('click', closeModal);
        modalOverlay && modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalOverlay.style.display === 'flex') closeModal();
        });

        // Helper to set modal fields
        function setModalField(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = value ?? 'N/A';
        }

        function setLoadingState() {
            setModalField('m-inmate-id', 'Loading…');
            setModalField('m-le-number', '');
            setModalField('m-age', '');
            setModalField('m-sex', '');
            setModalField('m-race', '');
            setModalField('m-booking-date', '');
            setModalField('m-status', '');
            setModalField('m-bond', '');
            setModalField('m-charges', 'Loading charges…');
            modalSubTitle.textContent = '';
        }

        // Attach click listeners to view buttons
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                // Parse inmate JSON from attribute
                const data = this.getAttribute('data-inmate');
                let inmate = null;
                try {
                    inmate = JSON.parse(data);
                } catch (err) {
                    console.error('Invalid inmate JSON', err);
                    inmate = null;
                }

                // Show base info immediately
                const name = inmate && inmate.name ? inmate.name : 'Inmate Details';
                modalTitle.textContent = name;
                const subtitleParts = [];
                if (inmate && inmate.inmate_id) subtitleParts.push('ID: ' + inmate.inmate_id);
                if (inmate && inmate.le_number) subtitleParts.push('LE#: ' + inmate.le_number);
                modalSubTitle.textContent = subtitleParts.join(' | ');

                setLoadingState();
                openModal();

                // Prepare payload - use dkt = inmate_id (docket), le = le_number
                const dkt = (inmate && (inmate.inmate_id || inmate.docket_number)) ? (inmate.inmate_id || inmate.docket_number) : '';
                const le  = (inmate && inmate.le_number) ? inmate.le_number : '';

                if (!dkt) {
                    setModalField('m-charges', 'No docket available for fetching details.');
                    return;
                }

                // POST to fetch_inmate_details.php
                const form = new FormData();
                form.append('dkt', dkt);
                form.append('le', le);

                fetch('fetch_inmate_details.php', {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                }).then(resp => resp.json())
                  .then(json => {
                      if (!json || !json.success) {
                          console.error('Fetch failed', json);
                          setModalField('m-charges', 'Failed to fetch details: ' + (json && json.message ? json.message : 'unknown error'));
                          // still populate base fields from row
                          setModalField('m-inmate-id', dkt);
                          setModalField('m-le-number', le || 'N/A');
                          setModalField('m-age', inmate && inmate.age ? inmate.age : 'N/A');
                          setModalField('m-status', inmate && inmate.in_jail ? 'Active - In Custody' : 'Released');
                          return;
                      }

                      const details = json.details || {};

                      setModalField('m-inmate-id', details.inmate_id || dkt);
                      setModalField('m-le-number', details.le_number || le || 'N/A');
                      setModalField('m-age', details.age || (inmate && inmate.age) || 'N/A');
                      setModalField('m-sex', details.sex || 'N/A');
                      setModalField('m-race', details.race || 'N/A');
                      setModalField('m-booking-date', details.booking_date || (inmate && inmate.booking_date) || 'N/A');
                      setModalField('m-status', (inmate && inmate.in_jail) ? 'Active - In Custody' : 'Released');
                      setModalField('m-bond', details.bond_amount || 'N/A');

                      // Charges
                      if (Array.isArray(details.charges) && details.charges.length > 0) {
                          const chargesText = details.charges.join('\\n');
                          setModalField('m-charges', chargesText);
                      } else {
                          setModalField('m-charges', 'No charges found');
                      }
                  })
                  .catch(err => {
                      console.error('Fetch error', err);
                      setModalField('m-charges', 'Error fetching details');
                      setModalField('m-inmate-id', dkt);
                      setModalField('m-le-number', le || 'N/A');
                  });
            });
        });
    });
})();