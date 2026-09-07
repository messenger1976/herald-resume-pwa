function renderHome(resume) {
  $("#heroName").text(resume.name);
  $("#heroSummary").text(resume.summary);
  $("#heroSummary").attr("style", "color:#ffffff !important;");
  $("#profilePhoto").attr("src", resume.photo).attr("alt", resume.name + " profile photo");
  $("#footerName").text(resume.name + " - " + resume.title);

  const skillsHtml = resume.coreSkills.slice(0, 6).map(function (skill) {
    return '<span class="badge text-bg-light border border-warning-subtle text-dark px-3 py-2 skill-chip">' + skill + "</span>";
  }).join("");
  $("#topSkills").html(skillsHtml);

  const metricsHtml = resume.metrics.map(function (metric) {
    return (
      '<div class="col-6 col-lg-3">' +
        '<div class="glass rounded-4 p-3 h-100">' +
          '<p class="small text-slate-400 mb-1">' + metric.label + "</p>" +
          '<p class="h5 mb-0 text-info-emphasis">' + metric.value + "</p>" +
        "</div>" +
      "</div>"
    );
  }).join("");
  $("#metricsCards").html(metricsHtml);

  const highlightsHtml = resume.experience.slice(0, 3).map(function (job) {
    return (
      '<div class="col-12 col-md-6 col-xl-4">' +
        '<article class="glass rounded-4 p-4 h-100">' +
          '<p class="text-info-emphasis fw-semibold mb-1">' + job.period + "</p>" +
          '<h3 class="h5 mb-1">' + job.role + "</h3>" +
          '<p class="text-muted mb-3">' + job.company + "</p>" +
          '<p class="small mb-0">' + job.highlights[0] + "</p>" +
        "</article>" +
      "</div>"
    );
  }).join("");
  $("#highlightsList").html(highlightsHtml);

  const stackHtml = Object.entries(resume.stack).map(function (entry) {
    const title = entry[0];
    const items = entry[1];
    const chips = items.map(function (item) {
      return '<span class="badge rounded-pill text-bg-light text-dark border border-warning-subtle me-1 mb-1">' + item + "</span>";
    }).join("");
    return (
      '<div class="glass rounded-4 p-3">' +
          '<h3 class="h6 text-info-emphasis mb-2">' + title + "</h3>" +
        "<div>" + chips + "</div>" +
      "</div>"
    );
  }).join("");
  $("#stackGrid").html(stackHtml);
}

function renderExperience(resume) {
  $("#footerName").text(resume.name + " - " + resume.title);
  const timelineHtml = resume.experience.map(function (job) {
    const bullets = job.highlights.map(function (line) {
      return "<li>" + line + "</li>";
    }).join("");
    return (
      '<article class="timeline-item glass rounded-4 p-4 mb-3">' +
        '<div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">' +
          '<div><h2 class="h5 mb-1">' + job.role + '</h2><p class="text-info-emphasis mb-0">' + job.company + "</p></div>" +
          '<div class="text-md-end"><p class="mb-0 text-muted">' + job.period + '</p><p class="small text-muted mb-0">' + job.location + "</p></div>" +
        "</div>" +
        "<ul class='mb-0 ps-3 small'>" + bullets + "</ul>" +
      "</article>"
    );
  }).join("");
  $("#timelineWrap").html(timelineHtml);
}

function renderProjects(resume) {
  $("#footerName").text(resume.name + " - " + resume.title);
  const cardsHtml = resume.projects.map(function (item) {
    return (
      '<div class="col-12 col-md-6">' +
        '<article class="glass rounded-4 p-4 h-100">' +
          '<p class="small text-info-emphasis mb-2">' + item.type + "</p>" +
          '<h2 class="h5 mb-2">' + item.name + "</h2>" +
          '<p class="text-muted small mb-3">' + item.description + "</p>" +
          '<a class="btn btn-outline-info btn-sm" target="_blank" rel="noopener" href="' + item.url + '">Visit Site</a>' +
        "</article>" +
      "</div>"
    );
  }).join("");
  $("#projectCards").html(cardsHtml);

  const tagHtml = resume.coreSkills.map(function (skill) {
    return '<span class="badge rounded-pill text-bg-light text-dark border border-warning-subtle px-3 py-2">' + skill + "</span>";
  }).join("");
  $("#buildTags").html(tagHtml);
}

function renderContact(resume) {
  $("#footerName").text(resume.name + " - " + resume.title);
  const links = [
    { label: "Phone", value: resume.phone, href: "tel:+639151874107" },
    { label: "Email", value: resume.email, href: "mailto:" + resume.email },
    { label: "Location", value: resume.location, href: "#" },
    { label: "LinkedIn", value: "View profile", href: resume.linkedin }
  ];
  const linksHtml = links.map(function (item) {
    const safeHref = item.href === "#" ? "#" : item.href;
    const target = safeHref.indexOf("http") === 0 ? ' target="_blank" rel="noopener"' : "";
    return (
      '<a class="text-decoration-none text-dark p-2 rounded-3 hover-link" href="' + safeHref + '"' + target + ">" +
        '<span class="small text-muted d-block">' + item.label + "</span>" +
        "<span>" + item.value + "</span>" +
      "</a>"
    );
  }).join("");
  $("#contactLinks").html(linksHtml);
}

function renderPortfolio(resume) {
  $("#footerName").text(resume.name + " - " + resume.title);

  const portfolioItems = resume.projects.filter(function (item) {
    return item.type === "Portfolio" || item.type === "Company Website" || item.type === "Company Platform";
  });

  const portfolioHtml = portfolioItems.map(function (item) {
    const detailsPages = {
      "Pension House App": "pension-house-details.html",
      "Lakambini 2026": "lakambini-details.html"
    };
    const detailsHref = detailsPages[item.name];
    const detailsBtn = detailsHref
      ? '<a class="btn btn-info btn-sm" href="' + detailsHref + '">View Details</a>'
      : "";
    return (
      '<div class="col-12 col-md-6 col-lg-4">' +
        '<article class="glass rounded-4 p-4 h-100">' +
          '<p class="small text-info-emphasis mb-2">' + item.type + "</p>" +
          '<h2 class="h5 mb-2">' + item.name + "</h2>" +
          '<p class="text-muted small mb-3">' + item.description + "</p>" +
          '<div class="d-flex flex-wrap gap-2">' +
            '<a class="btn btn-outline-info btn-sm" target="_blank" rel="noopener" href="' + item.url + '">Open Portfolio</a>' +
            detailsBtn +
          "</div>" +
        "</article>" +
      "</div>"
    );
  }).join("");

  $("#portfolioCards").html(portfolioHtml);

  const focusTags = [
    "Enterprise Web Applications",
    "E-Commerce Systems",
    "REST API Integration",
    "UI/UX and Frontend Development",
    "DevOps and CI/CD Delivery",
    "AI-Assisted Engineering with Cursor AI"
  ];

  const focusHtml = focusTags.map(function (tag) {
    return '<span class="badge rounded-pill text-bg-light text-dark border border-primary-subtle px-3 py-2">' + tag + "</span>";
  }).join("");

  $("#portfolioFocusTags").html(focusHtml);
}
