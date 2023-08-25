{{-- This file is to help you configure the gateway, here you can post tips for administrators how to configure the gateway --}}
{{-- The file appears on the gateway edit page --}}

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{!! __('admin.instruction') !!}</h3>
    </div>
    <div class="card-body">
        <div class="copy-url-container">
            Webhook URL:
            <textarea id="webhookUrl" style="display: none;">{{ route('payment.return', ['gateway' => $gateway->endpoint]) }}</textarea>

            <button class="btn btn-link" onclick="copyUrlToClipboard()">{{ route('payment.return', ['gateway' => $gateway->endpoint]) }}</button>
        </div>
        Stripe URL: <a target="_blank" class="btn btn-link" href="https://dashboard.stripe.com/developers">https://dashboard.stripe.com/developers</a>
        <div class="youtube-container">
            <iframe src="https://www.youtube.com/embed/2pxPd35eqXg"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen>
            </iframe>
        </div>
    </div>
</div>


<div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .youtube-container {
        position: relative;
        margin: 20px auto;
        width: 90%;
        padding-bottom: 40%;
    }

    .youtube-container iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
    }
</style>


<script>
    async function copyUrlToClipboard() {
        var copyText = document.getElementById("webhookUrl").value;

        try {
            await navigator.clipboard.writeText(copyText);
            // document.getElementById('modalTitle').innerText = 'Success';
            document.getElementById('modalTitle').innerText = 'URL copied to clipboard!';
        } catch (err) {
            // document.getElementById('modalTitle').innerText = 'Failed';
            document.getElementById('modalTitle').innerText = 'Failed to copy!';
        }

        $('#myModal').modal('show');
    }
</script>
