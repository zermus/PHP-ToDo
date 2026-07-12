// Quill rich-text editor for the task form. DOMPurify runs client-side for
// UX consistency; the server re-sanitizes with HTMLPurifier regardless.
document.addEventListener('DOMContentLoaded', function () {
    var editorElement = document.getElementById('editor');
    var form = document.getElementById('task-form');
    if (!editorElement || !form) {
        return;
    }

    var quill = new Quill('#editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ header: [1, 2, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                [{ indent: '-1' }, { indent: '+1' }],
                [{ align: [] }],
                ['link'],
                ['clean']
            ]
        }
    });

    var config = window.taskFormConfig || {};
    if (config.details) {
        quill.clipboard.dangerouslyPasteHTML(DOMPurify.sanitize(config.details));
    }

    form.addEventListener('submit', function () {
        var html = quill.root.innerHTML;
        document.getElementById('taskDetails').value = DOMPurify.sanitize(html);
    });

    // Checklist mode toggle.
    var isChecklist = document.getElementById('isChecklist');
    var checklistContainer = document.getElementById('checklistContainer');
    var detailsContainer = document.getElementById('taskDetailsContainer');

    function toggleTaskType() {
        if (isChecklist.checked) {
            detailsContainer.style.display = 'none';
            checklistContainer.style.display = 'block';
            if (document.querySelectorAll('#checklistItems input').length === 0) {
                addChecklistItem();
            }
        } else {
            checklistContainer.style.display = 'none';
            detailsContainer.style.display = 'block';
        }
    }

    function addChecklistItem() {
        var row = document.createElement('div');
        row.className = 'checklist-row';
        var input = document.createElement('input');
        input.setAttribute('type', 'text');
        input.setAttribute('name', 'checklist_new[]');
        row.appendChild(input);
        document.getElementById('checklistItems').appendChild(row);
    }

    isChecklist.addEventListener('change', toggleTaskType);
    document.getElementById('addChecklistItem').addEventListener('click', addChecklistItem);
    toggleTaskType();
});
