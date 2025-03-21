document.addEventListener("DOMContentLoaded", () => {
  const slides = document.querySelectorAll(".slide");
  let currentIndex = 0;

  function nextSlide() {
    slides[currentIndex].style.display = "none";
    currentIndex = (currentIndex + 1) % slides.length;
    slides[currentIndex].style.display = "block";
  }

  // Auto-rotate every 5 seconds
  setInterval(nextSlide, 5000);
});
