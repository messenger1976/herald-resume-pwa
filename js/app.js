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
  // jQuery wrappers: these two are driven with .addClass/.removeClass/.text().
  var statusBox = $("#formStatus");
  var fallbackBox = $("#formFallback");
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
    if (!statusBox.length) {
      return;
    }
    statusBox
      .removeClass("d-none text-info text-danger text-success")
      .addClass(type === "success" ? "text-success" : "text-danger")
      .text(message);
  }

  function clearStatus() {
    if (statusBox.length) {
      statusBox.addClass("d-none").text("");
    }
  }

  function showMailtoFallback(url) {
    if (!fallbackBox.length || !mailtoBtn) {
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

  // -------------------------------------------------------------------------
  // Field-level validation
  //
  // The form carries `novalidate`, so the browser's native bubbles are replaced
  // with Bootstrap's .is-invalid / .invalid-feedback. These rules mirror
  // hfolio_validate_inquiry_fields() in api/contact.php; anything the server
  // still rejects comes back in the `errors` map and is mapped onto the same
  // inputs by applyServerErrors().
  // -------------------------------------------------------------------------

  var FIELD_RULES = [
    {
      id: "fullName",
      field: "name",
      required: "Please enter your name.",
      max: 150,
      maxMessage: "Name must be 150 characters or fewer.",
      test: /^[\p{L}\p{M}'\-. \s]+$/u,
      testMessage: "Name can only contain letters, spaces, apostrophes, periods and hyphens."
    },
    {
      id: "emailAddress",
      field: "email",
      required: "Please enter your email address.",
      max: 255,
      maxMessage: "Email must be 255 characters or fewer.",
      test: /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/,
      testMessage: "Please enter a valid email address."
    },
    {
      id: "subject",
      field: "subject",
      required: "Please enter a subject.",
      max: 255,
      maxMessage: "Subject must be 255 characters or fewer."
    },
    {
      id: "message",
      field: "message",
      required: "Please enter a message.",
      min: 10,
      minMessage: "Message must be at least 10 characters.",
      max: 5000,
      maxMessage: "Message must be 5000 characters or fewer."
    }
  ];

  function eachRule(callback) {
    for (var i = 0; i < FIELD_RULES.length; i++) {
      var input = document.getElementById(FIELD_RULES[i].id);
      if (input) {
        callback(FIELD_RULES[i], input);
      }
    }
  }

  function ruleFor(fieldName) {
    for (var i = 0; i < FIELD_RULES.length; i++) {
      if (FIELD_RULES[i].field === fieldName) {
        return FIELD_RULES[i];
      }
    }
    return null;
  }

  function feedbackFor(input) {
    var feedback = input.parentNode.querySelector(".invalid-feedback[data-field-error]");
    if (!feedback) {
      feedback = document.createElement("div");
      feedback.className = "invalid-feedback";
      feedback.setAttribute("data-field-error", "1");
      feedback.setAttribute("role", "alert");
      input.parentNode.appendChild(feedback);
    }
    return feedback;
  }

  function setFieldError(input, message) {
    if (!input) {
      return;
    }
    input.classList.add("is-invalid");
    input.setAttribute("aria-invalid", "true");
    feedbackFor(input).textContent = message;
  }

  function clearFieldError(input) {
    if (!input) {
      return;
    }
    input.classList.remove("is-invalid");
    input.removeAttribute("aria-invalid");
    var feedback = input.parentNode.querySelector(".invalid-feedback[data-field-error]");
    if (feedback) {
      feedback.textContent = "";
    }
  }

  function clearAllFieldErrors() {
    eachRule(function (rule, input) {
      clearFieldError(input);
    });
    if (captchaCode) {
      clearFieldError(captchaCode);
    }
  }

  /** First problem with one input, or "" when it passes. */
  function checkInput(rule, input) {
    var value = (input.value || "").trim();
    if (value === "") {
      return rule.required || "";
    }
    if (rule.min && value.length < rule.min) {
      return rule.minMessage || "";
    }
    if (rule.max && value.length > rule.max) {
      return rule.maxMessage || "";
    }
    if (rule.test && !rule.test.test(value)) {
      return rule.testMessage || "";
    }
    return "";
  }

  /** Validate every field; returns {ok, firstInvalid, message}. */
  function validateFields() {
    var firstInvalid = null;
    var message = "";
    eachRule(function (rule, input) {
      var error = checkInput(rule, input);
      if (error) {
        setFieldError(input, error);
        if (!firstInvalid) {
          firstInvalid = input;
          message = error;
        }
      } else {
        clearFieldError(input);
      }
    });
    return { ok: !firstInvalid, firstInvalid: firstInvalid, message: message };
  }

  /**
   * Put the server's `errors` map (from the 400 response) on the matching
   * inputs. Returns the first offender so the caller can focus and summarise.
   */
  function applyServerErrors(errors) {
    var firstInvalid = null;
    var message = "";
    clearAllFieldErrors();
    for (var fieldName in errors) {
      if (!Object.prototype.hasOwnProperty.call(errors, fieldName)) {
        continue;
      }
      var rule = ruleFor(fieldName);
      var input = rule ? document.getElementById(rule.id) : null;
      if (!input) {
        continue;
      }
      setFieldError(input, String(errors[fieldName]));
      if (!firstInvalid) {
        firstInvalid = input;
        message = String(errors[fieldName]);
      }
    }
    return { firstInvalid: firstInvalid, message: message };
  }

  // Re-check a field as soon as the visitor fixes it, and on blur once it has
  // been filled in — never on a pristine field, so tabbing through is quiet.
  eachRule(function (rule, input) {
    input.addEventListener("input", function () {
      if (input.classList.contains("is-invalid")) {
        var error = checkInput(rule, input);
        if (error) {
          setFieldError(input, error);
        } else {
          clearFieldError(input);
        }
      }
    });
    input.addEventListener("blur", function () {
      if ((input.value || "").trim() !== "") {
        var error = checkInput(rule, input);
        if (error) {
          setFieldError(input, error);
        } else {
          clearFieldError(input);
        }
      }
    });
  });

  // The CAPTCHA input is not in FIELD_RULES (its answer is checked server-side),
  // so just clear its error as soon as the visitor starts correcting it.
  if (captchaCode) {
    captchaCode.addEventListener("input", function () {
      clearFieldError(captchaCode);
    });
  }

  /**
   * Point the <img> at api/captcha.php using the same data-api-base the AJAX
   * calls use. This used to be derived from app.js's own path, which produced
   * js/captcha.php — a 404, so the image challenge never rendered and only the
   * maths variant was solvable. cache=<token> forces a genuinely new image.
   */
  function buildCaptchaUrl(seed) {
    var base = apiBase + "captcha.php";
    var separator = base.indexOf("?") === -1 ? "?" : "&";
    return base + separator + "cache=" + encodeURIComponent(String(seed || Date.now()));
  }

  function resetCaptcha() {
    if (captchaCode) {
      captchaCode.value = "";
      clearFieldError(captchaCode);
    }
    if (captchaImage && captchaType === "image") {
      captchaImage.setAttribute("src", buildCaptchaUrl(Date.now()));
    }
  }

  var RECAPTCHA_FAILURE =
    "The security check could not load, so your message was not sent. Please refresh the page and try again, or email me directly.";

  function loadRecaptcha(siteKey) {
    return new Promise(function (resolve, reject) {
      if (window.grecaptcha && window.grecaptcha.execute) {
        resolve();
        return;
      }

      var settled = false;
      var timer = null;

      function done() {
        if (settled) {
          return;
        }
        settled = true;
        clearTimeout(timer);
        resolve();
      }

      function fail(message) {
        if (settled) {
          return;
        }
        settled = true;
        clearTimeout(timer);
        reject(new Error(message || RECAPTCHA_FAILURE));
      }

      // A tag that is already in the DOM has either loaded (and would have
      // defined grecaptcha) or failed. Adding a listener to it now never fires
      // again, which used to leave this promise pending forever and stranded
      // the submit button on "Sending..." with no explanation.
      if (document.querySelector("script[data-recaptcha-v3]")) {
        fail(RECAPTCHA_FAILURE);
        return;
      }

      // Belt and braces: a request that is blocked with neither load nor error
      // (extensions, flaky networks) must still release the caller.
      timer = setTimeout(function () {
        fail(RECAPTCHA_FAILURE);
      }, 10000);

      var script = document.createElement("script");
      script.src = "https://www.google.com/recaptcha/api.js?render=" + encodeURIComponent(siteKey);
      script.async = true;
      script.defer = true;
      script.setAttribute("data-recaptcha-v3", "1");
      script.onload = done;
      script.onerror = function () {
        fail(RECAPTCHA_FAILURE);
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

    var check = validateFields();
    if (!check.ok) {
      showStatus("danger", check.message);
      if (check.firstInvalid) {
        check.firstInvalid.focus();
      }
      return;
    }

    if (security.captcha_enabled && !captchaAnswer) {
      showStatus("danger", captchaNeedsImage ? "Please enter the code from the image." : "Please answer the security question.");
      if (captchaCode) {
        setFieldError(captchaCode, "Please complete the security check.");
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
          clearAllFieldErrors();
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
          // Field-level rejections from the API carry an `errors` map; anything
          // else (rate limit, CSRF, CAPTCHA) only has a summary message.
          var applied = result && result.errors ? applyServerErrors(result.errors) : null;

          if (result && result.captcha_failed && captchaCode) {
            setFieldError(captchaCode, result.message || "Please complete the security check.");
          }

          showStatus(
            "danger",
            (applied && applied.message) || (result && result.message) || "Could not send your message."
          );

          if (applied && applied.firstInvalid) {
            applied.firstInvalid.focus();
          }
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
