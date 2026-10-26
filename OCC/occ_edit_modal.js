window.initRecordEditModal = function (options) {
    const modalElement = document.getElementById(options.modalId);
    if (!modalElement || typeof bootstrap === 'undefined') return;

    const modal = new bootstrap.Modal(modalElement);
    const modalContent = modalElement.querySelector('.modal-content');

    document.addEventListener('click', async function (event) {
        const link = event.target.closest(options.linkSelector);
        if (!link) return;

        event.preventDefault();
        const editUrl = link.getAttribute(options.urlAttribute);
        modalContent.innerHTML = '<div class="modal-body text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">กำลังโหลด...</span></div></div>';
        modal.show();

        try {
            const separator = editUrl.includes('?') ? '&' : '?';
            const response = await fetch(editUrl + separator + 'modal=1');
            const html = await response.text();
            if (!response.ok) throw new Error(html || 'ไม่สามารถโหลดแบบฟอร์มแก้ไขได้');
            modalContent.innerHTML = html;
            if (typeof window.initOccFollowupStatus === 'function') {
                window.initOccFollowupStatus(modalContent);
            }
        } catch (error) {
            modalContent.innerHTML = '<div class="modal-header"><h5 class="modal-title">ไม่สามารถโหลดแบบฟอร์ม</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><div class="alert alert-danger mb-0"></div></div>';
            modalContent.querySelector('.alert').textContent = error.message;
        }
    });

    modalElement.addEventListener('submit', async function (event) {
        const form = event.target.closest(options.formSelector);
        if (!form) return;

        event.preventDefault();
        const submitButton = form.querySelector('button[type="submit"]');
        const message = modalElement.querySelector('[data-record-edit-message]');
        if (submitButton) submitButton.disabled = true;
        if (message) message.innerHTML = '';

        try {
            const formData = new FormData(form);
            formData.append(options.submitName, '1');
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error('เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON (HTTP ' + response.status + ') กรุณาตรวจสอบ URL และสถานะการเข้าสู่ระบบ');
            }
            if (!response.ok || !result.success) throw new Error(result.message || 'ไม่สามารถบันทึกการแก้ไขได้');

            modal.hide();
            window.location.reload();
        } catch (error) {
            if (message) {
                message.innerHTML = '<div class="alert alert-danger" role="alert"></div>';
                message.querySelector('.alert').textContent = error.message;
            }
            if (submitButton) submitButton.disabled = false;
        }
    });
};

window.initRecordEditModal({
    modalId: 'occEditModal',
    linkSelector: '[data-occ-edit-url]',
    urlAttribute: 'data-occ-edit-url',
    formSelector: '[data-occ-edit-form]',
    submitName: 'update_occ'
});
