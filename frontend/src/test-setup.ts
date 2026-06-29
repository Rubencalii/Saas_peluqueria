import { afterEach } from "vitest";
import { cleanup } from "@testing-library/react";

// Desmonta lo renderizado tras cada test (evita DOM duplicado entre tests).
afterEach(() => cleanup());
