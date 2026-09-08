(function () {
  var galleries = {
    frontend: [
      { src: "assets/images/bohol-island-tours/frontend/home.jpg", label: "Homepage · Hero & Packages" },
      { src: "assets/images/bohol-island-tours/frontend/destinations.jpg", label: "Destinations Guide" },
      { src: "assets/images/bohol-island-tours/frontend/package-2d1n.jpg", label: "Tour Package · 2D/1N" },
      { src: "assets/images/bohol-island-tours/frontend/rental.jpg", label: "Car / Van Rental" },
      { src: "assets/images/bohol-island-tours/frontend/about-bohol.jpg", label: "About Bohol Island" },
      { src: "assets/images/bohol-island-tours/frontend/contact.jpg", label: "Contact & Inquiry Form" }
    ],
    backend: [
      { src: "assets/images/bohol-island-tours/backend/admin-login.png", label: "Admin Login" },
      { src: "assets/images/bohol-island-tours/backend/dashboard.png", label: "Dashboard · Tourism Booking Engine" },
      { src: "assets/images/bohol-island-tours/backend/dashboard-darkmode.png", label: "Dashboard · Dark Mode" },
      { src: "assets/images/bohol-island-tours/backend/bookings.png", label: "Manage Bookings" },
      { src: "assets/images/bohol-island-tours/backend/booking-view.png", label: "Booking Details" },
      { src: "assets/images/bohol-island-tours/backend/booking-edit.png", label: "Edit Booking" },
      { src: "assets/images/bohol-island-tours/backend/calendar.png", label: "Booking Calendar" },
      { src: "assets/images/bohol-island-tours/backend/calendar-edit.png", label: "Calendar · Edit Event" },
      { src: "assets/images/bohol-island-tours/backend/tour-packages.png", label: "Tour Packages Offer" },
      { src: "assets/images/bohol-island-tours/backend/tour-package-edit.png", label: "Edit Tour Package" },
      { src: "assets/images/bohol-island-tours/backend/inquiries.png", label: "Manage Inquiries" },
      { src: "assets/images/bohol-island-tours/backend/inquiry-view.png", label: "Inquiry Thread + Reply" },
      { src: "assets/images/bohol-island-tours/backend/customers.png", label: "Customers / Guests" },
      { src: "assets/images/bohol-island-tours/backend/customer-details.png", label: "Customer Details" },
      { src: "assets/images/bohol-island-tours/backend/daily-sales-report.png", label: "Daily Sales Report" },
      { src: "assets/images/bohol-island-tours/backend/users.png", label: "Manage Admin Users" },
      { src: "assets/images/bohol-island-tours/backend/user-details.png", label: "User Details + Permissions" },
      { src: "assets/images/bohol-island-tours/backend/groups.png", label: "Manage Groups" },
      { src: "assets/images/bohol-island-tours/backend/group-view.png", label: "Group Details" },
      { src: "assets/images/bohol-island-tours/backend/group-edit.png", label: "Edit Group + Roles" },
      { src: "assets/images/bohol-island-tours/backend/roles.png", label: "Manage Roles" },
      { src: "assets/images/bohol-island-tours/backend/role-view.png", label: "Role Details" },
      { src: "assets/images/bohol-island-tours/backend/role-edit.png", label: "Edit Role + ACL Permissions" }
    ]
  };

  var state = {
    frontend: 0,
    backend: 0,
    lightboxSection: "frontend"
  };

  function shots(section) {
    return galleries[section] || [];
  }

  function setMain(section, index) {
    var list = shots(section);
    if (!list.length || index < 0 || index >= list.length) {
      return;
    }
    state[section] = index;
    var shot = list[index];
    $("#" + section + "Main").attr("src", shot.src).attr("alt", shot.label);
    $("#" + section + "Caption").text(shot.label);
    $("#" + section + "Thumbs .gallery-thumb").removeClass("is-active");
    $("#" + section + "Thumbs .gallery-thumb").eq(index).addClass("is-active");
  }

  function buildThumbs(section) {
    var html = shots(section).map(function (shot, index) {
      return (
        '<button type="button" class="gallery-thumb' + (index === 0 ? " is-active" : "") + '" data-section="' + section + '" data-index="' + index + '" role="listitem" aria-label="' + shot.label + '">' +
          '<img src="' + shot.src + '" alt="' + shot.label + '" loading="lazy">' +
          '<span class="gallery-thumb-label">' + shot.label + "</span>" +
        "</button>"
      );
    }).join("");
    $("#" + section + "Thumbs").html(html);
  }

  function openLightbox(section) {
    state.lightboxSection = section;
    var list = shots(section);
    var shot = list[state[section]];
    if (!shot) {
      return;
    }
    $("#lightboxImage").attr("src", shot.src).attr("alt", shot.label);
    $("#galleryLightbox").removeClass("d-none").attr("aria-hidden", "false");
    $("body").addClass("gallery-lock");
  }

  function closeLightbox() {
    $("#galleryLightbox").addClass("d-none").attr("aria-hidden", "true");
    $("body").removeClass("gallery-lock");
  }

  function initSection(section) {
    if (!$("#" + section + "Main").length) {
      return;
    }

    var list = shots(section);
    if (!list.length) {
      $("#" + section + "Pending").removeClass("d-none");
      $("#" + section + "Gallery").addClass("d-none");
      return;
    }

    $("#" + section + "Pending").addClass("d-none");
    $("#" + section + "Gallery").removeClass("d-none");
    buildThumbs(section);
    setMain(section, 0);

    $("#" + section + "Thumbs").on("click", ".gallery-thumb", function () {
      setMain(section, Number($(this).data("index")));
    });

    $("#" + section + "Main").on("click", function () {
      openLightbox(section);
    });
  }

  $(function () {
    initSection("frontend");
    initSection("backend");

    $("#lightboxClose").on("click", closeLightbox);

    $("#galleryLightbox").on("click", function (event) {
      if (event.target === this) {
        closeLightbox();
      }
    });

    $(document).on("keydown", function (event) {
      if ($("#galleryLightbox").hasClass("d-none")) {
        return;
      }
      var section = state.lightboxSection;
      var list = shots(section);
      if (!list.length) {
        return;
      }
      if (event.key === "Escape") {
        closeLightbox();
      }
      if (event.key === "ArrowRight") {
        setMain(section, (state[section] + 1) % list.length);
        openLightbox(section);
      }
      if (event.key === "ArrowLeft") {
        setMain(section, (state[section] - 1 + list.length) % list.length);
        openLightbox(section);
      }
    });
  });
})();
