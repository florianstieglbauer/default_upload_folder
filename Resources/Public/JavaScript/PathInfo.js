document.addEventListener('DOMContentLoaded', function () {
    if (!window.uploadFolderInfo) return;
    Object.keys(window.uploadFolderInfo).forEach(function(field) {
        var path = window.uploadFolderInfo[field];
        var container = document.querySelector('.form-group[data-local-field="' + field + '"]');
        if (!container) return;

        if (!container.querySelector('.upload-folder-info')) {
            var infoDiv = document.createElement('div');
            infoDiv.className = 'upload-folder-info help-block';
            infoDiv.style.marginBottom = '0';
            infoDiv.innerHTML = '<div class="form-text">Standard Upload Ordner:<br> <span class="badge badge-success">' + path + '</span></div>';

            var fileControls = container.querySelector('.form-control-wrap');
            if (fileControls) {
                fileControls.appendChild(infoDiv);
            } else {
                container.appendChild(infoDiv);
            }
        }
    });
});
