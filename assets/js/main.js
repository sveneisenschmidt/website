document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll("img.hero").forEach(function (img) {
    img.style.cursor = "pointer";
    img.addEventListener("click", function (event) {
      event.preventDefault();
      window.location.href = img.getAttribute("src");
    });
  });

  document.querySelectorAll(".img-wrapper").forEach(function (el) {
    var timer;
    var exif = el.querySelector(".exif");
    if (!exif) return;

    el.addEventListener("touchstart", function () {
      timer = setTimeout(function () {
        exif.classList.add("visible");
      }, 500);
    });

    el.addEventListener("touchend", function () {
      clearTimeout(timer);
      exif.classList.remove("visible");
    });

    el.addEventListener("touchcancel", function () {
      clearTimeout(timer);
      exif.classList.remove("visible");
    });
  });
});
