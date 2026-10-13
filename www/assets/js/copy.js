document.addEventListener("DOMContentLoaded", function () {
    // Each code block gets a Copy button in its top right corner. Inline code
    // copies itself on a click, because a button inside a sentence would
    // break the line. Without the Clipboard API, both stay plain text.
    if (!navigator.clipboard) return;

    function copy(text, done) {
        navigator.clipboard.writeText(text).then(done);
    }

    var blocks = document.querySelectorAll(".body pre");

    Array.prototype.forEach.call(blocks, function (block) {
        var text = block.innerText;

        var button = document.createElement("button");
        button.type = "button";
        button.className = "copy-button";
        button.textContent = "Copy";

        button.addEventListener("click", function () {
            copy(text, function () {
                button.textContent = "Copied";
                window.setTimeout(function () {
                    button.textContent = "Copy";
                }, 2000);
            });
        });

        block.classList.add("has-copy");
        block.appendChild(button);
    });

    var inline = document.querySelectorAll(".body code");

    Array.prototype.forEach.call(inline, function (code) {
        if (code.closest("pre")) return;

        code.classList.add("is-copyable");

        code.addEventListener("click", function () {
            // A click that ends a text selection copies nothing, so the
            // visitor can select a part of the code.
            var selection = window.getSelection();
            if (selection && selection.toString()) return;

            copy(code.textContent, function () {
                code.classList.add("is-copied");
                window.setTimeout(function () {
                    code.classList.remove("is-copied");
                }, 2000);
            });
        });
    });
});
