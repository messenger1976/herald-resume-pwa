(function () {
  var galleries = {
    backend: [
      { src: "assets/images/tapstemco/backend/login.png", label: "Admin Login · Ion Auth" },
      { src: "assets/images/tapstemco/backend/dashboard.jpg", label: "Dashboard · Membership & Portfolio KPIs" },
      { src: "assets/images/tapstemco/backend/collectors-map.jpg", label: "Collector Map · Field Locations" },
      { src: "assets/images/tapstemco/backend/members-listing.png", label: "Members · Listing" },
      { src: "assets/images/tapstemco/backend/members-current-state.png", label: "Members · Current State" },
      { src: "assets/images/tapstemco/backend/member-info.png", label: "Members · Member Info" },
      { src: "assets/images/tapstemco/backend/lms-masterfile.png", label: "LMS · Loan Masterfile" },
      { src: "assets/images/tapstemco/backend/lms-new-application.png", label: "LMS · New Loan Application" },
      { src: "assets/images/tapstemco/backend/lms-evaluation.png", label: "LMS · Loan Evaluation" },
      { src: "assets/images/tapstemco/backend/lms-approval.png", label: "LMS · Loan Approval" },
      { src: "assets/images/tapstemco/backend/lms-disbursement.png", label: "LMS · Loan Disbursement" },
      { src: "assets/images/tapstemco/backend/lms-release.png", label: "LMS · Loan Release" },
      { src: "assets/images/tapstemco/backend/lms-loan-info.png", label: "LMS · Loan Info" },
      { src: "assets/images/tapstemco/backend/lms-repayment-schedule.png", label: "LMS · Repayment Schedule" },
      { src: "assets/images/tapstemco/backend/loan-calculator.png", label: "Loan Calculator" },
      { src: "assets/images/tapstemco/backend/savings-account-list.png", label: "Savings · Account List" },
      { src: "assets/images/tapstemco/backend/savings-account-details.png", label: "Savings · Account Details" },
      { src: "assets/images/tapstemco/backend/savings-deposit-details.png", label: "Savings · Deposit Details" },
      { src: "assets/images/tapstemco/backend/savings-transaction-details.png", label: "Savings · Transaction Details" },
      { src: "assets/images/tapstemco/backend/cbu-masterfile.png", label: "CBU / Share · Masterfile" },
      { src: "assets/images/tapstemco/backend/cbu-details.png", label: "CBU / Share · Details" },
      { src: "assets/images/tapstemco/backend/cbu-ledger.png", label: "CBU / Share · Ledger" },
      { src: "assets/images/tapstemco/backend/accounting-coa.png", label: "Finance · Chart of Accounts" },
      { src: "assets/images/tapstemco/backend/accounting-journal-entry.png", label: "Finance · Journal Entry" },
      { src: "assets/images/tapstemco/backend/accounting-cash-receipt.png", label: "Finance · Cash Receipt" },
      { src: "assets/images/tapstemco/backend/accounting-cash-disbursement.png", label: "Finance · Cash Disbursement" },
      { src: "assets/images/tapstemco/backend/reports-menu.png", label: "Reports · Menu" },
      { src: "assets/images/tapstemco/backend/report-balance-sheet.png", label: "Reports · Balance Sheet" },
      { src: "assets/images/tapstemco/backend/report-financial-condition.png", label: "Reports · Statement of Financial Condition" }
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
