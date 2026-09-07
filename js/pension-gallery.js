(function () {
  var galleries = {
    frontend: [
      { src: "assets/images/pension-house/frontend/mainpage.png", label: "Homepage · Desktop" },
      { src: "assets/images/pension-house/frontend/mobile-mainpage.png", label: "Homepage · Mobile" },
      { src: "assets/images/pension-house/frontend/rooms.jpg", label: "Rooms Listing · Desktop" },
      { src: "assets/images/pension-house/frontend/mobile-rooms.png", label: "Rooms Listing · Mobile" },
      { src: "assets/images/pension-house/frontend/amenities.png", label: "Amenities" },
      { src: "assets/images/pension-house/frontend/gallery.jpg", label: "Gallery" },
      { src: "assets/images/pension-house/frontend/contactus.png", label: "Contact Us" },
      { src: "assets/images/pension-house/frontend/mobile-location.png", label: "Location · Mobile" },
      { src: "assets/images/pension-house/frontend/mycart.png", label: "Cart / Booking Summary" },
      { src: "assets/images/pension-house/frontend/checkout.png", label: "Checkout" },
      { src: "assets/images/pension-house/frontend/qrpayment.png", label: "QR Ph Payment" },
      { src: "assets/images/pension-house/frontend/loginpage.png", label: "Login · Desktop" },
      { src: "assets/images/pension-house/frontend/mobile-login.png", label: "Login · Mobile" },
      { src: "assets/images/pension-house/frontend/mydashboard.png", label: "My Dashboard · Bookings" },
      { src: "assets/images/pension-house/frontend/myinvoices.png", label: "My Invoices · Desktop" },
      { src: "assets/images/pension-house/frontend/mobile-myinvoice.png", label: "My Invoices · Mobile" },
      { src: "assets/images/pension-house/frontend/myinvoices-qrph.png", label: "Invoice · QR Ph Details" },
      { src: "assets/images/pension-house/frontend/myprofile.png", label: "My Profile" },
      { src: "assets/images/pension-house/frontend/myaccountsecurity.png", label: "Account Security" },
      { src: "assets/images/pension-house/frontend/myinquiry.png", label: "Send Inquiry" }
    ],
    backend: [
      { src: "assets/images/pension-house/backend/admin-login.png", label: "Admin Login" },
      { src: "assets/images/pension-house/backend/dashboard.png", label: "Admin Dashboard" },
      { src: "assets/images/pension-house/backend/dashboard-darkmode.png", label: "Dashboard · Dark Mode" },
      { src: "assets/images/pension-house/backend/bookings.png", label: "Manage Bookings" },
      { src: "assets/images/pension-house/backend/calendar.png", label: "Booking Calendar" },
      { src: "assets/images/pension-house/backend/calendar-darkmode.png", label: "Calendar · Dark Mode" },
      { src: "assets/images/pension-house/backend/rooms.png", label: "Manage Rooms" },
      { src: "assets/images/pension-house/backend/room-edit.png", label: "Edit Room + Gallery Upload" },
      { src: "assets/images/pension-house/backend/customers.png", label: "Customers / Guests" },
      { src: "assets/images/pension-house/backend/customers-details.png", label: "Customer Details" },
      { src: "assets/images/pension-house/backend/inquiries.png", label: "Manage Inquiries" },
      { src: "assets/images/pension-house/backend/inquiries-details.png", label: "Inquiry Thread + Email Sync" },
      { src: "assets/images/pension-house/backend/invoices.png", label: "Room Billing Invoices" },
      { src: "assets/images/pension-house/backend/invoice-details.png", label: "Invoice Details + Charges" },
      { src: "assets/images/pension-house/backend/payments.png", label: "Payment Records" },
      { src: "assets/images/pension-house/backend/payment-report.png", label: "Payments Report" },
      { src: "assets/images/pension-house/backend/billing-collections.png", label: "Billing & Collections" },
      { src: "assets/images/pension-house/backend/daily-sales-report.png", label: "Daily Sales Report" },
      { src: "assets/images/pension-house/backend/events.png", label: "Events Management" },
      { src: "assets/images/pension-house/backend/event-revenue.png", label: "Event Revenue Report" },
      { src: "assets/images/pension-house/backend/users.png", label: "Manage Admin Users" },
      { src: "assets/images/pension-house/backend/user-details.png", label: "User Details + Permissions" },
      { src: "assets/images/pension-house/backend/groups.png", label: "Manage Groups" },
      { src: "assets/images/pension-house/backend/group-details.png", label: "Edit Group + Roles" },
      { src: "assets/images/pension-house/backend/roles.png", label: "Manage Roles" },
      { src: "assets/images/pension-house/backend/roles-details.png", label: "Edit Role + ACL Permissions" },
      { src: "assets/images/pension-house/backend/activity-logs.png", label: "System Activity Logs" },
      { src: "assets/images/pension-house/backend/activity-logs-details.png", label: "Activity Log Details" },
      { src: "assets/images/pension-house/backend/email-smtp.png", label: "Email / SMTP Settings" },
      { src: "assets/images/pension-house/backend/backup.png", label: "Database Backup" }
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
