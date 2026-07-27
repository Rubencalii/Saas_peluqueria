import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { replace, loginMock, setTokenMock } = vi.hoisted(() => ({
  replace: vi.fn(),
  loginMock: vi.fn(),
  setTokenMock: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ replace }) }));
// Se conserva el módulo real para que `AdminApiError` siga siendo la clase que
// comprueba la página con `instanceof`; solo se sustituyen las llamadas de red.
vi.mock("@/lib/admin", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/admin")>()),
  admin: { login: loginMock },
  setToken: setTokenMock,
}));

import { AdminApiError } from "@/lib/admin";
import PanelLogin from "@/app/panel/login/page";

function rellenaCredenciales(email = "admin@salon.es", password = "admin1234") {
  fireEvent.change(screen.getByLabelText("Email"), { target: { value: email } });
  fireEvent.change(screen.getByLabelText("Contraseña"), { target: { value: password } });
  fireEvent.click(screen.getByText("Entrar"));
}

describe("Panel · Login", () => {
  beforeEach(() => vi.clearAllMocks());

  it("guarda el token y redirige al panel tras un login correcto", async () => {
    loginMock.mockResolvedValue({ token: "TOKEN123", expires_at: "", user: {} });
    render(<PanelLogin />);

    rellenaCredenciales();

    await waitFor(() => expect(setTokenMock).toHaveBeenCalledWith("TOKEN123"));
    expect(loginMock).toHaveBeenCalledWith("admin@salon.es", "admin1234", undefined);
    expect(replace).toHaveBeenCalledWith("/panel");
  });

  it("muestra error y no redirige si las credenciales fallan", async () => {
    loginMock.mockRejectedValue(new AdminApiError("INVALID_CREDENTIALS", "no", 401));
    render(<PanelLogin />);

    rellenaCredenciales("x@y.es", "nope");

    expect(await screen.findByText(/incorrectos/i)).toBeTruthy();
    expect(setTokenMock).not.toHaveBeenCalled();
    expect(replace).not.toHaveBeenCalled();
  });

  it("pide el segundo factor y reenvía el código sin mostrar error", async () => {
    loginMock.mockRejectedValueOnce(new AdminApiError("TOTP_REQUIRED", "falta 2fa", 401));
    render(<PanelLogin />);

    rellenaCredenciales();

    const codigo = await screen.findByLabelText(/Código de verificación/i);
    expect(screen.queryByText(/incorrectos/i)).toBeNull();

    loginMock.mockResolvedValueOnce({ token: "TOKEN2FA", expires_at: "", user: {} });
    fireEvent.change(codigo, { target: { value: "123456" } });
    fireEvent.click(screen.getByText("Entrar"));

    await waitFor(() => expect(setTokenMock).toHaveBeenCalledWith("TOKEN2FA"));
    expect(loginMock).toHaveBeenLastCalledWith("admin@salon.es", "admin1234", "123456");
    expect(replace).toHaveBeenCalledWith("/panel");
  });

  it("avisa si el código de verificación es incorrecto", async () => {
    loginMock.mockRejectedValueOnce(new AdminApiError("TOTP_REQUIRED", "falta 2fa", 401));
    render(<PanelLogin />);

    rellenaCredenciales();
    const codigo = await screen.findByLabelText(/Código de verificación/i);

    loginMock.mockRejectedValueOnce(new AdminApiError("TOTP_INVALID", "malo", 401));
    fireEvent.change(codigo, { target: { value: "000000" } });
    fireEvent.click(screen.getByText("Entrar"));

    expect(await screen.findByText(/Código de verificación incorrecto/i)).toBeTruthy();
    expect(setTokenMock).not.toHaveBeenCalled();
    expect(replace).not.toHaveBeenCalled();
  });
});
