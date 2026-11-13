<style>
    #receipt-print-root { display: none; }
    @media print {
        body.receipt-printing {
            display: block !important;
            height: auto !important;
            min-height: 0 !important;
            margin: 0 !important;
            overflow: visible !important;
            background: white !important;
        }
        body.receipt-printing > :not(#receipt-print-root) { display: none !important; }
        body.receipt-printing > #receipt-print-root {
            display: block !important;
            width: 100%;
            max-width: 100%;
            color: black;
            background: white;
        }
        #receipt-print-root .receipt {
            position: static !important;
            width: 100% !important;
            max-width: 100%;
            overflow: visible !important;
        }
    }
</style>
<script>
    (() => {
        function clearReceiptPrint() {
            document.getElementById('receipt-print-root')?.remove();
            document.getElementById('receipt-print-page')?.remove();
            document.body.classList.remove('receipt-printing');
        }

        function prepareReceiptPrint(receipt) {
            clearReceiptPrint();
            if (!receipt) return false;
            const root = document.createElement('div');
            root.id = 'receipt-print-root';
            const copy = receipt.cloneNode(true);
            copy.style.width = '100%';
            root.appendChild(copy);

            // Ukur di luar modal agar posisi fixed dan scroll tidak memotong struk.
            const width = receipt.getBoundingClientRect().width || 80 * 96 / 25.4;
            root.style.cssText = `display: block !important; position: absolute; left: 0; top: 0; visibility: hidden; width: ${width}px;`;
            document.body.appendChild(root);
            const height = root.getBoundingClientRect().height;
            root.removeAttribute('style');
            const pageStyle = document.createElement('style');
            pageStyle.id = 'receipt-print-page';
            const widthMm = width * 25.4 / 96 + 4;
            const heightMm = Math.ceil(height * 25.4 / 96 + 5);
            pageStyle.textContent = `@media print { @page { size: ${widthMm}mm ${heightMm}mm; margin: 2mm; } }`;
            document.body.appendChild(pageStyle);
            document.body.classList.add('receipt-printing');
            return true;
        }

        window.printReceipt = async (contentId) => {
            const receipt = document.getElementById(contentId)?.querySelector('.receipt');
            if (!receipt) return;
            await Promise.all([...receipt.querySelectorAll('img')].map(image => image.decode().catch(() => {})));
            if (!prepareReceiptPrint(receipt)) return;
            try {
                window.print();
            } catch (error) {
                clearReceiptPrint();
                throw error;
            }
        };
        window.addEventListener('beforeprint', () => {
            if (document.getElementById('receipt-print-root')) return;
            const receipt = [...document.querySelectorAll('#receipt-content .receipt, #pos-receipt-content .receipt')]
                .find(element => element.getClientRects().length > 0);
            prepareReceiptPrint(receipt);
        });
        window.addEventListener('afterprint', clearReceiptPrint);
    })();
</script>
