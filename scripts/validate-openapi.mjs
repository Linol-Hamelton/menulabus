import SwaggerParser from "@apidevtools/swagger-parser";
import path from "node:path";
import process from "node:process";

const file = process.argv[2] || "docs/openapi.yaml";
const fullPath = path.resolve(process.cwd(), file);

try {
  const api = await SwaggerParser.validate(fullPath);
  const title = api?.info?.title || "(no title)";
  const version = api?.info?.version || "(no version)";
  console.log(`OK: ${file} (${title} ${version})`);
} catch (err) {
  console.error(`FAIL: ${file}`);
  console.error(err?.message || String(err));
  process.exit(1);
}

