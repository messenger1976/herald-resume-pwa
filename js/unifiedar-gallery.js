(function () {
  var galleries = {
    frontend: [
      { src: "assets/images/unifiedar/frontend/home.jpg", label: "Homepage · PrinTech IQ / UnifiedAR" },
      { src: "assets/images/unifiedar/frontend/pricing.jpg", label: "Pricing · Plans & Campaign Services" },
      { src: "assets/images/unifiedar/frontend/partner-program.jpg", label: "Partner Program" },
      { src: "assets/images/unifiedar/frontend/contact.png", label: "Contact Us" }
    ],
    backend: [
      { src: "assets/images/unifiedar/backend/admin-login.png", label: "Admin Login" },
      { src: "assets/images/unifiedar/backend/admin-login-mfa.png", label: "Login · MFA Challenge" },
      { src: "assets/images/unifiedar/backend/system-dashboard.png", label: "System Dashboard" },
      { src: "assets/images/unifiedar/backend/master-resellers.png", label: "Master Resellers" },
      { src: "assets/images/unifiedar/backend/billing-settings.png", label: "Billing Settings" },
      { src: "assets/images/unifiedar/backend/active-campaigns.png", label: "Active Campaigns" },
      { src: "assets/images/unifiedar/backend/add-campaign.png", label: "Add New Campaign" },
      { src: "assets/images/unifiedar/backend/splash-compliance.png", label: "Splash & Compliance" },
      { src: "assets/images/unifiedar/backend/cta-edit.png", label: "Edit CTA Style" },
      { src: "assets/images/unifiedar/backend/ebusiness-card.png", label: "eBusiness Card Style" },
      { src: "assets/images/unifiedar/backend/ebusiness-card-cta.png", label: "eBusiness Card · CTA Step" },
      { src: "assets/images/unifiedar/backend/digital-retargeting.png", label: "Digital Retargeting" },
      { src: "assets/images/unifiedar/backend/custom-qrcode.png", label: "Custom QR Code Designer" },
      { src: "assets/images/unifiedar/backend/campaign-assets.png", label: "Campaign Assets Monitor" },
      { src: "assets/images/unifiedar/backend/duplicate-assets.png", label: "Duplicate Assets / Replication Queue" },
      { src: "assets/images/unifiedar/backend/system-activity.png", label: "System Activity / Audit Trail" },
      { src: "assets/images/unifiedar/backend/error-monitor.png", label: "Error Monitor" }
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
