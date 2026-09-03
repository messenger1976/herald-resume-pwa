function registerServiceWorker() {
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", function () {
      navigator.serviceWorker.register("service-worker.js").catch(function (error) {
        console.error("Service worker registration failed:", error);
      });
    });
  }
}

function initContactForm() {
  $("#contactForm").on("submit", function (event) {
    event.preventDefault();

    const payload = {
      name: $("#fullName").val(),
      email: $("#emailAddress").val(),
      message: $("#message").val(),
      submittedAt: new Date().toISOString()
    };

    // Demo AJAX request pattern for contact submission.
    $.ajax({
      url: "assets/data/resume.json",
      method: "GET",
      dataType: "json"
    }).done(function () {
      $("#formStatus")
        .removeClass("d-none text-danger")
        .addClass("text-info")
        .text("Thanks! Message saved locally for demo. Payload: " + payload.name + " / " + payload.email);
      $("#contactForm")[0].reset();
    }).fail(function () {
      $("#formStatus")
        .removeClass("d-none text-info")
        .addClass("text-danger")
        .text("Could not process your message while offline. Please try again.");
    });
  });
}

function bootPage(resume) {
  const page = $("body").data("page");
  if (page === "home") {
    renderHome(resume);
  }
  if (page === "experience") {
    renderExperience(resume);
  }
  if (page === "projects") {
    renderProjects(resume);
  }
  if (page === "portfolio") {
    renderPortfolio(resume);
  }
  if (page === "contact") {
    renderContact(resume);
    initContactForm();
  }
}

$(function () {
  $.ajax({
    url: "assets/data/resume.json",
    method: "GET",
    dataType: "json"
  }).done(function (resume) {
    bootPage(resume);
  }).fail(function () {
    console.error("Could not load resume data.");
  });

  registerServiceWorker();
});
