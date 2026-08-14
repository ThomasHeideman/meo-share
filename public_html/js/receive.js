document.addEventListener('DOMContentLoaded', function () {
    const downloadBtn = document.getElementById('downloadBtn');
    const receiveCard = document.getElementById('receiveCard');

    if (!downloadBtn || !receiveCard) {
        return;
    }

    downloadBtn.addEventListener('click', function () {
        setTimeout(function () {
            receiveCard.innerHTML =
                '<h1 class="status-title">Download gestart</h1>'
                + '<p class="status-message is-success">Dit bestand was eenmalig beschikbaar en is nu permanent vernietigd.</p>';
        }, 300);
    });
});
