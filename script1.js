document.addEventListener("DOMContentLoaded", () => {
    const slides = document.querySelectorAll(".slide");
    let currentIndex = 0;
  
    // Initialize slider
    slides[currentIndex].classList.add("active");
    
    function nextSlide() {
      slides[currentIndex].classList.remove("active");
      currentIndex = (currentIndex + 1) % slides.length;
      slides[currentIndex].classList.add("active");
      updateSliderPosition();
    }
  
    function updateSliderPosition() {
      const slider = document.querySelector(".slides-container");
      slider.style.transform = `translateX(-${currentIndex * 100}%)`;
    }
  
    // Auto-rotate every 5 seconds
    setInterval(nextSlide, 5000);
  });
  