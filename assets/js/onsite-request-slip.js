(function () {
    const sheet = document.getElementById('onsiteRequestSlipSheet');
    if (!sheet) {
        return;
    }

    const requestNumber = sheet.dataset.requestNumber || 'onsite-request-slip';
    const safeName = requestNumber.replace(/[^\w\-]+/g, '_');

    // Slip size: 4.25in x 6.5in
    const SLIP_WIDTH_IN = parseFloat(sheet.dataset.slipWidth || '4.25') || 4.25;
    const SLIP_HEIGHT_IN = parseFloat(sheet.dataset.slipHeight || '6.5') || 6.5;
    const SLIP_WIDTH_MM = SLIP_WIDTH_IN * 25.4;
    const SLIP_HEIGHT_MM = SLIP_HEIGHT_IN * 25.4;

    function setBusy(button, busy) {
        if (!button) {
            return;
        }
        button.disabled = busy;
        button.classList.toggle('is-busy', busy);
    }

    function captureSheet() {
        if (typeof html2canvas !== 'function') {
            throw new Error('Image capture library is not loaded.');
        }

        return html2canvas(sheet, {
            scale: 3,
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false,
        });
    }

    function downloadImage(button) {
        setBusy(button, true);
        captureSheet()
            .then(function (canvas) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        throw new Error('Unable to create image file.');
                    }
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = safeName + '-onsite-slip.png';
                    link.click();
                    URL.revokeObjectURL(link.href);
                }, 'image/png');
            })
            .catch(function (error) {
                alert(error.message || 'Unable to download image.');
            })
            .finally(function () {
                setBusy(button, false);
            });
    }

    function downloadPdf(button) {
        setBusy(button, true);
        captureSheet()
            .then(function (canvas) {
                if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
                    throw new Error('PDF library is not loaded.');
                }

                const imgData = canvas.toDataURL('image/png');
                const pdf = new window.jspdf.jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: [SLIP_WIDTH_MM, SLIP_HEIGHT_MM],
                });

                pdf.addImage(imgData, 'PNG', 0, 0, SLIP_WIDTH_MM, SLIP_HEIGHT_MM);
                pdf.save(safeName + '-onsite-slip.pdf');
            })
            .catch(function (error) {
                alert(error.message || 'Unable to download PDF.');
            })
            .finally(function () {
                setBusy(button, false);
            });
    }

    document.querySelectorAll('[data-onsite-slip-download]').forEach(function (button) {
        button.addEventListener('click', function () {
            const type = button.getAttribute('data-onsite-slip-download');
            if (type === 'pdf') {
                downloadPdf(button);
            } else if (type === 'png') {
                downloadImage(button);
            }
        });
    });

    if (document.body.classList.contains('auto-print')) {
        window.addEventListener('load', function () {
            window.setTimeout(function () {
                window.print();
            }, 250);
        });
    }
})();
