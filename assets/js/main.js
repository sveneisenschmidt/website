document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll("img.hero").forEach(function (img) {
    img.style.cursor = "pointer";
    img.addEventListener("click", function (event) {
      event.preventDefault();
      window.location.href = img.getAttribute("src");
    });
  });
});
