function registerServiceWorker() {
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", function () {
      navigator.serviceWorker.register("service-worker.js").catch(function (error) {
        console.error("Service worker registration failed:", error);
      });
    });
  }
}

/**
 * Contact form: CSRF bootstrap, self-hosted CAPTCHA, optional reCAPTCHA v3,
 * then POST to api/contact.php. Mirrors the bodarepensionhouse inquiry flow.
 */
function initContactForm() {
  var form = document.getElementById("contactForm");
  if (!form || typeof $ === "undefined") {
    return;
  }

  var submitBtn = document.getElementById("contactSubmitBtn");
  var csrfInput = document.getElementById("contactCsrfToken");
  var statusBox = document.getElementById("formStatus");
  var fallbackBox = document.getElementById("formFallback");
  var mailtoBtn = document.getElementById("formMailtoBtn");
  var captchaBlock = document.getElementById("captchaBlock");
  var captchaLabel = document.getElementById("captchaLabel");
  var captchaWrap = document.getElementById("captchaImageWrap");
  var captchaImage = document.getElementById("captchaImage");
  var captchaCode = document.getElementById("captchaCode");
  var captchaHelp = document.getElementById("captchaHelp");
  var captchaRefresh = document.getElementById("captchaRefresh");

  // Pages are served from /resume/*.html, so "api/x.php" resolves from the
  // document base. The data attributes allow an override for other layouts.
  var apiBase = form.getAttribute("data-api-base") || "api/";
  var apiHint = form.getAttribute("data-api-hint") || "/resume/api/";
  var captchaType = "";
  var captchaNeedsImage = false;

  var security = {
    csrf_token: "",
    honeypot_field: "company_url",
    captcha_enabled: false,
    recaptcha_enabled: false,
    recaptcha_site_key: "",
    recaptcha_action: "contact_submit",
    ready: false
  };

  function showStatus(type, message) {
    if (!statusBox) {
      return;
    }
    statusBox
      .removeClass("d-none text-info text-danger text-success")
      .addClass(type === "success" ? "text-success" : "text-danger")
      .text(message);
  }

  function clearStatus() {
    if (statusBox) {
      statusBox.addClass("d-none").text("");
    }
  }

  function showMailtoFallback(url) {
    if (!fallbackBox || !mailtoBtn) {
      return;
    }
    if (url) {
      mailtoBtn.setAttribute("href", url);
      fallbackBox.removeClass("d-none");
      return;
    }
    fallbackBox.addClass("d-none");
    mailtoBtn.setAttribute("href", "#");
  }

  function lockForm(locked, label) {
    if (!submitBtn) {
      return;
    }
    submitBtn.disabled = !!locked;
    submitBtn.textContent = locked ? (label || "Sending...") : "Send Message";
  }

  /**
   * Point the <img> at api/captcha.php through the PHP location that served this
   * page. cache=<token> forces a genuinely new image each time. The session
   * cookie is path-scoped to /resume/, so the query-string fallback still keeps
   * the session the puzzle was generated in.
   */
  function buildCaptchaUrl(seed) {
    var base = "api/captcha.php";
    var scripts = document.getElementsByTagName("script");
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute("src") || "";
      if (/(^|\/)app\.js(\?|$)/.test(src)) {
        base = src.replace(/app\.js(\?.*)?$/, "captcha.php");
        break;
      }
    }
    var separator = base.indexOf("?") === -1 ? "?" : "&";
    return base + separator + "cache=" + encodeURIComponent(String(seed || Date.now()));
  }

  function resetCaptcha() {
    if (captchaCode) {
      captchaCode.value = "";
    }
    if (captchaImage && captchaType === "image") {
      captchaImage.setAttribute("src", buildCaptchaUrl(Date.now()));
    }
  }

  function loadRecaptcha(siteKey) {
    return new Promise(function (resolve, reject) {
      if (window.grecaptcha && window.grecaptcha.execute) {
        resolve();
        return;
      }
      var existing = document.querySelector("script[data-recaptcha-v3]");
      if (existing) {
        existing.addEventListener("load", function () {
          resolve();
        });
        existing.addEventListener("error", function () {
          reject(new Error("Failed to load CAPTCHA."));
        });
        return;
      }
      var script = document.createElement("script");
      script.src = "https://www.google.com/recaptcha/api.js?render=" + encodeURIComponent(siteKey);
      script.async = true;
      script.defer = true;
      script.setAttribute("data-recaptcha-v3", "1");
      script.onload = function () {
        resolve();
      };
      script.onerror = function () {
        reject(new Error("Failed to load CAPTCHA."));
      };
      document.head.appendChild(script);
    });
  }

  function getRecaptchaToken() {
    if (!security.recaptcha_enabled || !security.recaptcha_site_key) {
      return Promise.resolve("");
    }
    return loadRecaptcha(security.recaptcha_site_key).then(function () {
      return new Promise(function (resolve, reject) {
        window.grecaptcha.ready(function () {
          window.grecaptcha
            .execute(security.recaptcha_site_key, {
              action: security.recaptcha_action || "contact_submit"
            })
            .then(resolve)
            .catch(reject);
        });
      });
    });
  }

  function applySecurity(result) {
    security.csrf_token = result.csrf_token;
    security.honeypot_field = result.honeypot_field || "company_url";
    security.recaptcha_enabled = !!result.recaptcha_enabled;
    security.recaptcha_site_key = result.recaptcha_site_key || "";
    security.recaptcha_action = result.recaptcha_action || "contact_submit";

    if (csrfInput) {
      csrfInput.value = security.csrf_token;
    }

    var captcha = result.captcha || {};
    security.captcha_enabled = !!captcha.enabled;
    captchaType = captcha.type || "";
    captchaNeedsImage = captchaType === "image";

    if (captchaBlock) {
      if (security.captcha_enabled) {
        captchaBlock.removeAttribute("hidden");

        if (captchaNeedsImage) {
          if (captchaWrap) {
            captchaWrap.removeAttribute("hidden");
          }
          if (captchaImage) {
            captchaImage.setAttribute("src", buildCaptchaUrl(Date.now()));
          }
          if (captchaLabel) {
            captchaLabel.textContent = "Security Check";
          }
          if (captchaHelp) {
            captchaHelp.textContent =
              "Type the " + (captcha.length || 5) + " characters shown above. Click 'New code' if it is unreadable.";
          }
        } else {
          // Math challenge: the prompt is plain text, no image needed.
          if (captchaWrap) {
            captchaWrap.setAttribute("hidden", "hidden");
          }
          if (captchaLabel) {
            captchaLabel.textContent = captcha.prompt || "Security question";
          }
          if (captchaHelp) {
            captchaHelp.textContent = "Answer the question above to prove you are human.";
          }
        }

        if (captchaCode) {
          captchaCode.value = "";
        }
      } else {
        captchaBlock.setAttribute("hidden", "hidden");
      }
    }

    security.ready = true;

    if (security.recaptcha_enabled) {
      return loadRecaptcha(security.recaptcha_site_key).catch(function () {
        /* loaded on submit instead */
      });
    }

    return null;
  }

  function refreshSecurity() {
    return $.ajax({
      url: apiBase + "csrf.php",
      method: "GET",
      dataType: "json",
      cache: false
    }).then(function (result) {
      if (!result || !result.success || !result.csrf_token) {
        throw new Error("Could not initialize form security. Please refresh.");
      }

      return applySecurity(result);
    });
  }

  function describeFailure(xhr) {
    var payload = xhr && xhr.responseJSON ? xhr.responseJSON : null;

    if (payload && payload.message) {
      if (payload.refresh_security) {
        // The token/CAPTCHA pair is spent or stale — silently get fresh ones.
        Promise.resolve()
          .then(function () {
            return refreshSecurity();
          })
          .catch(function () {
            /* surfaced on the next submit attempt */
          });
      }
      if (payload.mailto) {
        showMailtoFallback(payload.mailto);
      }

      return payload.message;
    }

    if (xhr && (xhr.status === 0 || xhr.status === 404 || xhr.status === 405 || xhr.status === 500)) {
      return (
        "The message service could not be reached. Your host may not be running PHP for this folder — " +
        "check " +
        apiHint +
        "contact.php — or email me directly at the address on this page."
      );
    }

    return (xhr && xhr.message) || "Could not send your message. Please try again.";
  }

  refreshSecurity().catch(function (err) {
    showStatus("danger", (err && err.message) ? err.message : "Could not initialize form security.");
    if (submitBtn) {
      submitBtn.disabled = true;
    }
  });

  if (captchaRefresh) {
    captchaRefresh.addEventListener("click", function () {
      resetCaptcha();
    });
  }

  $("#contactForm").on("submit", function (event) {
    event.preventDefault();
    clearStatus();
    showMailtoFallback("");

    var honeypot = document.getElementById("company_url");
    if (honeypot && honeypot.value) {
      // Bot: pretend it worked and quietly reset.
      showStatus("success", "Thank you! Your message has been sent.");
      form.reset();
      return;
    }

    var name = ($("#fullName").val() || "").trim();
    var email = ($("#emailAddress").val() || "").trim();
    var subject = ($("#subject").val() || "").trim();
    var message = ($("#message").val() || "").trim();
    var captchaAnswer = captchaCode ? ($(captchaCode).val() || "").trim() : "";

    if (!name || !email || !subject || !message) {
      showStatus("danger", "Please fill in all fields.");
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showStatus("danger", "Please enter a valid email address.");
      return;
    }
    if (security.captcha_enabled && !captchaAnswer) {
      showStatus("danger", captchaNeedsImage ? "Please enter the code from the image." : "Please answer the security question.");
      if (captchaCode) {
        captchaCode.focus();
      }
      return;
    }

    lockForm(true);

    var securityReady = (security.ready && security.csrf_token)
      ? Promise.resolve()
      : Promise.resolve(refreshSecurity());

    securityReady
      .then(function () {
        return getRecaptchaToken();
      })
      .then(function (recaptchaToken) {
        var payload = {
          name: name,
          email: email,
          subject: subject,
          message: message,
          csrf_token: security.csrf_token,
          captcha_answer: captchaAnswer,
          recaptcha_token: recaptchaToken || ""
        };
        payload[security.honeypot_field || "company_url"] = honeypot ? honeypot.value : "";

        return $.ajax({
          url: apiBase + "contact.php",
          method: "POST",
          contentType: "application/json",
          dataType: "json",
          data: JSON.stringify(payload)
        });
      })
      .then(function (result) {
        if (result && result.success) {
          var confirmation = result.message || "Thank you! Your message has been sent.";
          if (result.ticket_id) {
            confirmation += " Your reference is " + result.ticket_id + ".";
          }
          if (result.email_sent) {
            confirmation += " A confirmation email is on its way to " + email + ".";
          }
          showStatus("success", confirmation);
          if (result.mailto) {
            showMailtoFallback(result.mailto);
          }
          form.reset();
        } else {
          showStatus("danger", (result && result.message) ? result.message : "Could not send your message.");
        }
      })
      .catch(function (xhr) {
        showStatus("danger", describeFailure(xhr));
      })
      .then(function () {
        return refreshSecurity().catch(function () {
          /* ignore — the visitor can still reload the page */
        });
      })
      .then(function () {
        lockForm(false);
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
  if (page === "pension-details" || page === "lakambini-details" || page === "bohol-tours-details" || page === "unifiedar-details" || page === "tapstemco-details" || page === "roxas-details" || page === "labason-details") {
    $("#footerName").text(resume.name + " - " + resume.title);
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
