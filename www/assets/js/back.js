document.addEventListener("DOMContentLoaded", function () {
    // In the HTML, the Back link points to the front page, so it works
    // without JavaScript. When the visitor came from a page of this site,
    // the link goes back in the browser history. A visitor who came from
    // another site or opened the URL directly keeps the link to the front page.
    var link = document.querySelector("a[data-back]");
    if (!link) return;

    if (history.length < 2) return;

    var origin = window.location.origin;
    if (
        document.referrer !== origin &&
        document.referrer.indexOf(origin + "/") !== 0
    )
        return;

    // After a like, Website\Likes\Likes redirects to the same post. The
    // previous entry in the history is then that post, and history.back()
    // would show it again. The visitor keeps the link to the front page.
    if (new URL(document.referrer).pathname === window.location.pathname) return;

    link.addEventListener("click", function (event) {
        if (
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        )
            return;

        event.preventDefault();
        history.back();
    });
});
