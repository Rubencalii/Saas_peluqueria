"use client";

import { useState } from "react";
import { loadStripe, type Stripe } from "@stripe/stripe-js";
import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { api, ApiError } from "@/lib/api";
import { formatPrice } from "@/lib/format";
import { useLang } from "@/components/LangProvider";

const PK = process.env.NEXT_PUBLIC_STRIPE_PK ?? "";
let stripePromise: Promise<Stripe | null> | null = null;
function getStripe(): Promise<Stripe | null> | null {
  if (!PK) return null;
  stripePromise ??= loadStripe(PK);
  return stripePromise;
}

/**
 * Pago opcional del depósito de una cita (doc 13 §2.5). Sólo aparece si el
 * servicio tiene depósito y hay clave pública de Stripe configurada
 * (NEXT_PUBLIC_STRIPE_PK); si no, queda oculto.
 */
export function Deposit({
  appointmentId,
  code,
  amount,
}: {
  appointmentId: number;
  code: string;
  amount: number;
}) {
  const { t, locale, intl } = useLang();
  const [clientSecret, setClientSecret] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [paid, setPaid] = useState(false);

  if (!PK) return null; // pago online no configurado

  async function start() {
    setLoading(true);
    setError(null);
    try {
      const res = await api.deposit(appointmentId, code);
      setClientSecret(res.client_secret);
    } catch (e) {
      if (e instanceof ApiError && (e.code === "PAYMENTS_DISABLED" || e.status === 503)) {
        setError(t("dep.unavailable"));
      } else if (e instanceof ApiError && e.code === "ALREADY_PAID") {
        setPaid(true);
      } else {
        setError(e instanceof Error ? e.message : t("dep.startError"));
      }
    } finally {
      setLoading(false);
    }
  }

  if (paid) {
    return <p className="mt-4 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{t("dep.paid")}</p>;
  }

  const stripe = getStripe();
  if (clientSecret && stripe) {
    return (
      <div className="mt-4 rounded-2xl border border-border p-4 text-left">
        <p className="mb-3 text-sm font-medium">{t("dep.payTitle", { amount: formatPrice(amount, intl) })}</p>
        <Elements stripe={stripe} options={{ clientSecret, locale: locale === "ca" ? "es" : locale }}>
          <PayForm onPaid={() => setPaid(true)} />
        </Elements>
      </div>
    );
  }

  return (
    <div className="mt-4">
      <button onClick={start} disabled={loading} className="btn-ghost">
        {loading ? t("dep.preparing") : t("dep.payBtn", { amount: formatPrice(amount, intl) })}
      </button>
      {error ? <p className="mt-2 text-sm text-red-700">{error}</p> : null}
    </div>
  );
}

function PayForm({ onPaid }: { onPaid: () => void }) {
  const { t } = useLang();
  const stripe = useStripe();
  const elements = useElements();
  const [paying, setPaying] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function pay() {
    if (!stripe || !elements) return;
    setPaying(true);
    setError(null);
    const { error: err, paymentIntent } = await stripe.confirmPayment({
      elements,
      redirect: "if_required",
    });
    if (err) {
      setError(err.message ?? t("dep.payError"));
      setPaying(false);
      return;
    }
    if (paymentIntent && (paymentIntent.status === "succeeded" || paymentIntent.status === "processing")) {
      onPaid();
    } else {
      setPaying(false);
    }
  }

  return (
    <div className="space-y-3">
      <PaymentElement />
      {error ? <p className="text-sm text-red-700">{error}</p> : null}
      <button onClick={pay} disabled={paying || !stripe} className="btn-primary w-full">
        {paying ? t("dep.processing") : t("dep.payNow")}
      </button>
    </div>
  );
}
