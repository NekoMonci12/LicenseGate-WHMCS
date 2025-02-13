<div class="row">
    <div class="col-xs-6">
        <div class="btn-group" role="group">
            <a class="btn btn-primary text-white">License</a>
            <a class="btn btn-success text-white" id="copyLicense" data-license="{$licensesKey}" onclick="copyToClipboard()">{$licensesKey}</a>
        </div>
    </div>
</div>

<script>
function copyToClipboard() {
    var licenseKey = document.getElementById("copyLicense").getAttribute("data-license");
    var tempInput = document.createElement("textarea");
    tempInput.value = licenseKey;
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand("copy");
    document.body.removeChild(tempInput);
    alert("License key copied to clipboard!");
}
</script>
