(function () {
    function parseWallClock(value) {
        const parts = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(value));
        if (!parts) return NaN;

        const year = Number(parts[1]);
        const month = Number(parts[2]);
        const day = Number(parts[3]);
        const hour = Number(parts[4]);
        const minute = Number(parts[5]);
        const second = Number(parts[6] || 0);
        const timestamp = Date.UTC(year, month - 1, day, hour, minute, second);
        const parsed = new Date(timestamp);
        if (
            parsed.getUTCFullYear() !== year
            || parsed.getUTCMonth() !== month - 1
            || parsed.getUTCDate() !== day
            || parsed.getUTCHours() !== hour
            || parsed.getUTCMinutes() !== minute
            || parsed.getUTCSeconds() !== second
        ) {
            return NaN;
        }
        return timestamp;
    }

    window.initOccFollowupStatus = function (root) {
        root.querySelectorAll('[data-occ-followup-date]').forEach(function (followupInput) {
            if (followupInput.dataset.statusInitialized === 'true') return;
            followupInput.dataset.statusInitialized = 'true';

            const form = followupInput.closest('form');
            const statusInput = form && form.querySelector('[data-occ-status]');
            if (!statusInput) return;

            const statusRadios = form.querySelectorAll('[data-occ-status-value]');
            const statusMessage = form.querySelector('[data-occ-status-message]');
            function showStatus(message, isOnTime) {
                if (!statusMessage) return;
                statusMessage.textContent = message;
                statusMessage.className = message
                    ? 'small fw-semibold mt-2 ' + (isOnTime ? 'text-success' : 'text-danger')
                    : 'small fw-semibold mt-2';
            }

            function updateStatus() {
                const followupValue = followupInput.value;
                const dischargeValue = followupInput.dataset.dischargeDate || '';
                if (!followupValue) {
                    followupInput.setCustomValidity('');
                    statusInput.value = '';
                    statusRadios.forEach(function (radio) { radio.checked = false; });
                    showStatus('', false);
                    return;
                }

                const followupTime = parseWallClock(followupValue);
                if (followupInput.hasAttribute('data-next-appointment-date')) {
                    const nextAppointmentDate = followupInput.dataset.nextAppointmentDate || '';
                    const nextAppointmentTime = parseWallClock(nextAppointmentDate + 'T00:00');
                    if (!Number.isFinite(followupTime) || !Number.isFinite(nextAppointmentTime)) {
                        followupInput.setCustomValidity('ไม่พบวันที่นัด PMR ครั้งถัดไปหรือวันที่ Follow-up ที่ถูกต้อง');
                        statusInput.value = '';
                        statusRadios.forEach(function (radio) { radio.checked = false; });
                        showStatus('ไม่พบวันที่นัดครั้งก่อนที่ถูกต้อง จึงคำนวณสถานะไม่ได้', false);
                        return;
                    }

                    followupInput.setCustomValidity('');
                    const status = followupValue.slice(0, 10) <= nextAppointmentDate
                        ? 'มาตรงนัด'
                        : 'มาไม่ตรงนัด';
                    statusInput.value = status;
                    statusRadios.forEach(function (radio) {
                        radio.checked = radio.dataset.occStatusValue === status;
                    });
                    showStatus(status, status === 'มาตรงนัด');
                    return;
                }

                const dischargeTime = parseWallClock(dischargeValue);
                if (!Number.isFinite(followupTime) || !Number.isFinite(dischargeTime)) {
                    followupInput.setCustomValidity('ไม่พบวันที่จำหน่ายหรือวันที่ Follow-up ที่ถูกต้อง');
                    statusInput.value = '';
                    statusRadios.forEach(function (radio) { radio.checked = false; });
                    showStatus('ไม่พบวันที่จำหน่ายหรือวันที่ Follow-up ที่ถูกต้อง จึงคำนวณสถานะไม่ได้', false);
                    return;
                }

                if (followupTime < dischargeTime) {
                    followupInput.setCustomValidity('วันที่ Follow-up ต้องไม่ก่อนวันจำหน่าย');
                    statusInput.value = '';
                    statusRadios.forEach(function (radio) { radio.checked = false; });
                    showStatus('วันที่ Follow-up ต้องไม่ก่อนวันจำหน่าย', false);
                    return;
                }

                followupInput.setCustomValidity('');
                const status = followupTime - dischargeTime <= 14 * 24 * 60 * 60 * 1000
                    ? 'มาตรงนัด'
                    : 'มาไม่ตรงนัด';
                statusInput.value = status;
                statusRadios.forEach(function (radio) {
                    radio.checked = radio.dataset.occStatusValue === status;
                });
                showStatus(status, status === 'มาตรงนัด');
            }

            followupInput.addEventListener('change', updateStatus);
            followupInput.addEventListener('input', updateStatus);
            updateStatus();
        });
    };
})();
