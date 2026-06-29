import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { replace, loginMock, setTokenMock } = vi.hoisted(() => ({
  replace: vi.fn(),
  loginMock: vi.fn(),
  setTokenMock: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ replace }) }));
vi.mock("@/lib/admin", () => ({ admin: { login: loginMock }, setToken: setTokenMock }));

import PanelLogin from "@/app/panel/login/page";

describe("Panel · Login", () => {
  beforeEach(() => vi.clearAllMocks());

  it("guarda el token y redirige al panel tras un login correcto", async () => {
    loginMock.mockResolvedValue({ token: "TOKEN123", expires_at: "", user: {} });
    render(<PanelLogin />);

    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "admin@salon.es" } });
    fireEvent.change(screen.getByLabelText("Contraseña"), { target: { value: "admin1234" } });
    fireEvent.click(screen.getByText("Entrar"));

    await waitFor(() => expect(setTokenMock).toHaveBeenCalledWith("TOKEN123"));
    expect(loginMock).toHaveBeenCalledWith("admin@salon.es", "admin1234");
    expect(replace).toHaveBeenCalledWith("/panel");
  });

  it("muestra error y no redirige si las credenciales fallan", async () => {
    loginMock.mockRejectedValue(new Error("bad"));
    render(<PanelLogin />);

    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "x@y.es" } });
    fireEvent.change(screen.getByLabelText("Contraseña"), { target: { value: "nope" } });
    fireEvent.click(screen.getByText("Entrar"));

    expect(await screen.findByText(/incorrectos/i)).toBeTruthy();
    expect(setTokenMock).not.toHaveBeenCalled();
    expect(replace).not.toHaveBeenCalled();
  });
});
