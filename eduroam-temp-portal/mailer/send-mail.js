#!/usr/bin/env node
"use strict";

const nodemailer = require("nodemailer");

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => {
      data += chunk;
      if (data.length > 1024 * 1024) {
        reject(new Error("payload too large"));
        process.stdin.destroy();
      }
    });
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

function requireString(obj, key) {
  const value = obj[key];
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(`missing ${key}`);
  }
  return value.trim();
}

function normalizeList(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item).trim()).filter(Boolean);
  }
  if (typeof value === "string") {
    return value.split(",").map((item) => item.trim()).filter(Boolean);
  }
  return [];
}

(async () => {
  try {
    const payload = JSON.parse(await readStdin());
    const smtp = payload.smtp || {};
    const to = normalizeList(payload.to);
    const cc = normalizeList(payload.cc);
    const bcc = normalizeList(payload.bcc);

    if (to.length === 0 && cc.length === 0 && bcc.length === 0) {
      throw new Error("missing recipients");
    }

    const transporter = nodemailer.createTransport({
      host: requireString(smtp, "host"),
      port: Number(smtp.port || 587),
      secure: Boolean(smtp.secure),
      auth: {
        user: requireString(smtp, "user"),
        pass: requireString(smtp, "pass"),
      },
    });

    const result = await transporter.sendMail({
      from: payload.from,
      to,
      cc,
      bcc,
      subject: requireString(payload, "subject"),
      text: payload.text || "",
      html: payload.html || undefined,
      replyTo: payload.replyTo || undefined,
    });

    process.stdout.write(JSON.stringify({
      ok: true,
      messageId: result.messageId || "",
      accepted: result.accepted || [],
      rejected: result.rejected || [],
    }));
  } catch (error) {
    process.stderr.write(error && error.stack ? error.stack : String(error));
    process.exit(1);
  }
})();
