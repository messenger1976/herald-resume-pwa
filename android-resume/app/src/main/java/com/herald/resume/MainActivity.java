package com.herald.resume;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;

public class MainActivity extends Activity {
    private WebView resumeView;
    @SuppressLint("SetJavaScriptEnabled") @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        resumeView = new WebView(this);
        resumeView.getSettings().setJavaScriptEnabled(true);
        resumeView.getSettings().setDomStorageEnabled(true);
        resumeView.getSettings().setAllowFileAccess(true);
        resumeView.getSettings().setAllowFileAccessFromFileURLs(true);
        resumeView.getSettings().setAllowUniversalAccessFromFileURLs(true);
        resumeView.getSettings().setLoadWithOverviewMode(true);
        resumeView.getSettings().setUseWideViewPort(true);
        resumeView.setWebChromeClient(new WebChromeClient());
        resumeView.setWebViewClient(new ResumeClient());
        resumeView.loadUrl("file:///android_asset/index.html");
        setContentView(resumeView);
    }
    @Override public void onBackPressed() { if (resumeView.canGoBack()) resumeView.goBack(); else super.onBackPressed(); }
    private class ResumeClient extends WebViewClient {
        @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl(); String scheme = uri.getScheme();
            if ("tel".equals(scheme) || "mailto".equals(scheme) || "http".equals(scheme) || "https".equals(scheme)) {
                startActivity(new Intent(Intent.ACTION_VIEW, uri)); return true;
            }
            return false;
        }
    }
}
