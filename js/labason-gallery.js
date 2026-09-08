(function () {
  var galleries = {
    backend: [
      { src: "assets/images/labason-water-district/backend/login.png", label: "Secure Login · Water Billing Administration" },
      { src: "assets/images/labason-water-district/backend/dashboard.png", label: "Dashboard · Sales, Zones & Tickets" },
      { src: "assets/images/labason-water-district/backend/customers-listing.png", label: "Customers · Listing by Zone" },
      { src: "assets/images/labason-water-district/backend/add-customer.png", label: "Customers · Add Customer" },
      { src: "assets/images/labason-water-district/backend/customer-edit.png", label: "Customers · Edit Account" },
      { src: "assets/images/labason-water-district/backend/customer-soa.png", label: "Customers · Statement of Account" },
      { src: "assets/images/labason-water-district/backend/meter-readings-listing.png", label: "Finance · Meter Readings Listing" },
      { src: "assets/images/labason-water-district/backend/customer-payments-listing.png", label: "Finance · Customer Payments Listing" },
      { src: "assets/images/labason-water-district/backend/cash-payment-module.png", label: "Finance · Cash Payment Module" },
      { src: "assets/images/labason-water-district/backend/add-leaking-entry.png", label: "Finance · Add Leaking Entry" },
      { src: "assets/images/labason-water-district/backend/manage-leaking-ledger.png", label: "Finance · Manage Leaking Ledger" },
      { src: "assets/images/labason-water-district/backend/ar-adjustment.png", label: "Accounting · AR Adjustment" },
      { src: "assets/images/labason-water-district/backend/daily-collection-report.png", label: "Reports · Daily Collection Report" },
      { src: "assets/images/labason-water-district/backend/monthly-billing-report.png", label: "Reports · Monthly Billing Report" },
      { src: "assets/images/labason-water-district/backend/low-to-no-consumption-report.png", label: "Reports · Low to No Consumption" },
      { src: "assets/images/labason-water-district/backend/customer-report.png", label: "Reports · Customer Report" },
      { src: "assets/images/labason-water-district/backend/aging-ar-report.png", label: "Reports · Aging of Accounts Receivable" },
      { src: "assets/images/labason-water-district/backend/arrears-monitoring.png", label: "Reports · Arrears Monitoring" },
      { src: "assets/images/labason-water-district/backend/customer-payment-monitoring.png", label: "Reports · Customer Payment Monitoring" },
      { src: "assets/images/labason-water-district/backend/monthly-income-analytic.png", label: "Reports · Monthly Income Analytic" },
      { src: "assets/images/labason-water-district/backend/manage-employees.png", label: "HR · Manage Employees" },
      { src: "assets/images/labason-water-district/backend/manage-roles.png", label: "Admin · Roles & Responsibilities" },
      { src: "assets/images/labason-water-district/backend/mobile-notifications.png", label: "Ops · Mobile Notifications" },
      { src: "assets/images/labason-water-district/backend/sms-analytics-dashboard.png", label: "Ops · SMS Analytics Dashboard" },
      { src: "assets/images/labason-water-district/backend/system-activity.png", label: "Ops · System Activity" },
      { src: "assets/images/labason-water-district/backend/database-backup.png", label: "Admin · Database Backup" }
    ]
  };

  var state = {
    backend: 0,
    lightboxSection: "backend"
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
