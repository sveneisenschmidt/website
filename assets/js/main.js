document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll("img.hero").forEach(function (img) {
    var lastTap = 0;
    img.addEventListener("touchend", function (event) {
      event.preventDefault();
      var now = Date.now();
      var wrapper = img.closest(".img-wrapper");
      if (now - lastTap < 350) {
        lastTap = 0;
        window.location.href = img.getAttribute("src");
      } else {
        if (wrapper) {
          wrapper.classList.toggle("exif-visible");
        }
      }
      lastTap = now;
    });

    img.addEventListener("click", function (event) {
      if (event.pointerType === "touch") return;
      event.preventDefault();
      window.location.href = img.getAttribute("src");
    });
  });

  document.addEventListener("touchend", function (event) {
    if (!event.target.closest(".img-wrapper")) {
      document.querySelectorAll(".img-wrapper.exif-visible").forEach(function (wrapper) {
        wrapper.classList.remove("exif-visible");
      });
    }
  });
});
