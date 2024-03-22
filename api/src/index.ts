import { Elysia } from "elysia";
import { cors } from "@elysiajs/cors";
import { $ } from "bun";

const app = new Elysia()
  .use(
    cors({
      origin: "co.mmon.co",
    })
  )
  .get("/healthcheck", () => ({
    ok: true,
  }))
  .post("/boinc2docker-app", async (req) => {
    if (typeof req.body !== "string") return req.error(400, "missing body");

    const { cmd } = JSON.parse(req.body);
    if (!cmd) return req.error(400, "missing command");

    const result = await $`echo ${cmd} > out.txt`;
    if (result.stderr.length)
      return req.error(500, { error: result.stderr.join("\n") });

    return { ok: true, result: result.stdout.join("\n") };
  })
  .listen(8000);

console.log(
  `🦊 Elysia is running at ${app.server?.hostname}:${app.server?.port}`
);
