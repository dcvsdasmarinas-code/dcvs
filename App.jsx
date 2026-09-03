import React, { useState } from "react";
import {
  ChevronDown,
  Search,
  Plus,
  X,
  ChevronLeft,
  ChevronRight,
  LayoutList,
  Calendar,
  Flag,
} from "lucide-react";

const deals = [
  { id: 1, name: "Nile Site Revamp", owner: "Mike Banner", amount: "$8,750", close: "Mar 10, 2026", stage: "Pilot Phase", color: "bg-violet-500" },
  { id: 2, name: "Beta Launch Site", owner: "Tony Stark", amount: "$12,300", close: "Mar 22, 2026", stage: "New Business", color: "bg-orange-500" },
  { id: 3, name: "Acme Corp Upgrade", owner: "Shawon Green", amount: "$7,800", close: "Apr 02, 2026", stage: "Negotiation", color: "bg-emerald-500" },
  { id: 4, name: "Nova Solutions Site", owner: "Jordan Smith", amount: "$15,500", close: "Apr 18, 2026", stage: "Pilot Phase", color: "bg-blue-500" },
  { id: 5, name: "Apex Group Landing", owner: "Ravi Patel", amount: "$6,100", close: "May 01, 2026", stage: "Sent", color: "bg-rose-500" },
  { id: 6, name: "Zenith Portal Page", owner: "Denial Justin", amount: "$11,900", close: "May 14, 2026", stage: "Approved", color: "bg-amber-500" },
  { id: 7, name: "Global Net Hub Site", owner: "Mike Tyson", amount: "$13,400", close: "Jun 03, 2026", stage: "New Business", color: "bg-cyan-500" },
  { id: 8, name: "Prime Tech Landing", owner: "David Jones", amount: "$9,000", close: "Jun 21, 2026", stage: "Pilot Phase", color: "bg-indigo-500" },
  { id: 9, name: "Elite Systems Page", owner: "Waker Den", amount: "$14,700", close: "Jul 09, 2026", stage: "Negotiation", color: "bg-fuchsia-500" },
  { id: 10, name: "Summit Digital Site", owner: "Harden Lee", amount: "$5,200", close: "Jul 27, 2026", stage: "Sent", color: "bg-teal-500" },
  { id: 11, name: "Vanguard Access Page", owner: "Bryan Den", amount: "$16,800", close: "Aug 05, 2026", stage: "Approved", color: "bg-pink-500" },
  { id: 12, name: "Pinnacle Vision Site", owner: "Franzen den", amount: "$10,500", close: "Aug 19, 2026", stage: "New Business", color: "bg-lime-600" },
  { id: 13, name: "Optima Route Landing", owner: "Harry John", amount: "$6,900", close: "Sep 02, 2026", stage: "Pilot Phase", color: "bg-purple-500" },
  { id: 14, name: "Core Matrix Upgrade", owner: "Shon Smith", amount: "$17,200", close: "Sep 15, 2026", stage: "Negotiation", color: "bg-red-500" },
  { id: 15, name: "Titan Crest Launch", owner: "Henry cavil", amount: "$8,400", close: "Oct 01, 2026", stage: "Sent", color: "bg-sky-500" },
  { id: 16, name: "Royal Gate Site Revamp", owner: "John Wick", amount: "$19,100", close: "Oct 20, 2026", stage: "Approved", color: "bg-yellow-500" },
  { id: 17, name: "AJ Investments", owner: "Lio Ven", amount: "$9,200", close: "Nov 04, 2026", stage: "New Business", color: "bg-green-500" },
];

const navItems = [
  { label: "Dashboard" },
  { label: "Contacts", count: 46 },
  { label: "Inbox" },
  { label: "Deals", count: 18, active: true },
  { label: "Companies" },
  { label: "Workflow Automation", count: 12 },
  { label: "More" },
];

const collectionItems = ["Products", "My deals flow", "Support"];
const bottomItems = ["Sales", "Operations", "Partner team", "Quality"];

function Avatar({ name, color }) {
  const initials = name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();
  return (
    <div
      className={`w-6 h-6 rounded-full ${color} flex items-center justify-center text-[10px] font-medium text-white shrink-0`}
    >
      {initials}
    </div>
  );
}

function FilterPill({ label }) {
  return (
    <button className="flex items-center gap-1.5 px-2.5 py-1.5 text-[13px] text-gray-600 hover:bg-gray-100 rounded-md transition-colors">
      {label}
      <ChevronDown size={14} className="text-gray-400" />
    </button>
  );
}

function Field({ label, required, children }) {
  return (
    <div className="mb-4">
      <label className="block text-[13px] font-medium text-gray-700 mb-1.5">
        {label}
        {required && <span className="text-gray-400"> *</span>}
      </label>
      {children}
    </div>
  );
}

export default function DealsPage() {
  const [selected, setSelected] = useState(null);
  const [checkedRows, setCheckedRows] = useState({});

  const toggleRow = (id) => {
    setCheckedRows((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  return (
    <div className="h-screen w-full flex bg-gray-50 text-gray-900 font-sans text-[13px] overflow-hidden">
      {/* Sidebar */}
      <aside className="w-[220px] shrink-0 border-r border-gray-200 bg-white flex flex-col">
        <div className="px-4 py-3.5 flex items-center gap-2 border-b border-gray-100">
          <div className="w-6 h-6 rounded-full bg-amber-400 flex items-center justify-center text-[11px] font-semibold text-white">
            M
          </div>
          <span className="font-medium text-gray-800 flex-1 truncate">Mike Banner</span>
          <ChevronDown size={14} className="text-gray-400" />
        </div>

        <nav className="flex-1 overflow-y-auto px-2 py-3">
          <ul className="space-y-0.5">
            {navItems.map((item) => (
              <li key={item.label}>
                <button
                  className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded-md text-[13px] transition-colors ${
                    item.active
                      ? "bg-gray-100 text-gray-900 font-medium"
                      : "text-gray-600 hover:bg-gray-50"
                  }`}
                >
                  <span>{item.label}</span>
                  {item.count && (
                    <span className="text-[11px] text-gray-400">{item.count}</span>
                  )}
                </button>
              </li>
            ))}
          </ul>

          <div className="mt-5">
            <div className="px-2.5 mb-1 text-[11px] font-medium text-gray-400 uppercase tracking-wide">
              Collections
            </div>
            <ul className="space-y-0.5">
              {collectionItems.map((label) => (
                <li key={label}>
                  <button className="w-full text-left px-2.5 py-1.5 rounded-md text-[13px] text-gray-600 hover:bg-gray-50 transition-colors">
                    {label}
                  </button>
                </li>
              ))}
            </ul>
          </div>

          <div className="mt-5">
            <ul className="space-y-0.5">
              {bottomItems.map((label) => (
                <li key={label}>
                  <button className="w-full text-left px-2.5 py-1.5 rounded-md text-[13px] text-gray-600 hover:bg-gray-50 transition-colors">
                    {label}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </nav>

        <div className="p-3 border-t border-gray-100 space-y-2">
          <div className="bg-gray-50 rounded-lg p-2.5">
            <div className="text-[12px] text-gray-600 mb-1.5">Adding sample data</div>
            <div className="text-[11px] text-gray-400 mb-1.5">28 of 50 created</div>
            <div className="h-1 bg-gray-200 rounded-full overflow-hidden">
              <div className="h-full w-[56%] bg-gray-800 rounded-full" />
            </div>
          </div>
          <button className="w-full flex items-center justify-center gap-1.5 text-[13px] text-gray-600 py-1.5 hover:bg-gray-50 rounded-md transition-colors">
            <Plus size={14} /> Invite
          </button>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 flex flex-col min-w-0">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-3.5 border-b border-gray-200 bg-white shrink-0">
          <h1 className="text-[15px] font-semibold text-gray-900">Deals</h1>
          <div className="flex items-center gap-2 w-72 px-3 py-1.5 bg-gray-100 rounded-lg text-gray-400">
            <Search size={14} />
            <span className="flex-1 text-[13px]">Search...</span>
            <kbd className="text-[11px] bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-400">
              ⌘K
            </kbd>
          </div>
        </div>

        {/* Tabs */}
        <div className="flex items-center gap-5 px-6 pt-3 border-b border-gray-200 bg-white shrink-0">
          <button className="flex items-center gap-1.5 pb-2.5 text-[13px] font-medium text-gray-900 border-b-2 border-gray-900">
            <LayoutList size={14} /> All deals
          </button>
          <button className="pb-2.5 text-[13px] text-gray-500 hover:text-gray-700">
            My deals
          </button>
          <button className="flex items-center gap-1 pb-2.5 text-[13px] text-gray-500 hover:text-gray-700">
            <Plus size={14} /> Add view (2/5)
          </button>
        </div>

        {/* Filter bar */}
        <div className="flex items-center gap-1 px-6 py-2 border-b border-gray-200 bg-white shrink-0">
          <FilterPill label="Sales Pipeline" />
          <FilterPill label="Deal owner" />
          <FilterPill label="Create date" />
          <FilterPill label="Last activity date" />
          <FilterPill label="Close date" />
        </div>

        {/* Search row */}
        <div className="px-6 py-2.5 border-b border-gray-200 bg-white shrink-0">
          <div className="flex items-center gap-2 w-80 px-3 py-1.5 border border-gray-200 rounded-lg text-gray-400">
            <Search size={14} />
            <span className="text-[13px]">Search name, phone, email...</span>
          </div>
        </div>

        {/* Table */}
        <div className="flex-1 overflow-auto bg-white">
          <table className="w-full border-collapse">
            <thead className="sticky top-0 bg-white z-10">
              <tr className="border-b border-gray-200 text-left text-gray-500">
                <th className="w-10 px-4 py-2.5">
                  <input type="checkbox" className="rounded border-gray-300" />
                </th>
                <th className="px-2 py-2.5 font-medium">Deal Name</th>
                <th className="px-2 py-2.5 font-medium">Deal Owner</th>
                <th className="px-2 py-2.5 font-medium">Amount</th>
                <th className="px-2 py-2.5 font-medium">Close date</th>
              </tr>
            </thead>
            <tbody>
              {deals.map((deal) => (
                <tr
                  key={deal.id}
                  onClick={() => setSelected(deal)}
                  className={`border-b border-gray-100 cursor-pointer transition-colors ${
                    selected?.id === deal.id ? "bg-gray-50" : "hover:bg-gray-50"
                  }`}
                >
                  <td className="px-4 py-2.5" onClick={(e) => e.stopPropagation()}>
                    <input
                      type="checkbox"
                      checked={!!checkedRows[deal.id]}
                      onChange={() => toggleRow(deal.id)}
                      className="rounded border-gray-300"
                    />
                  </td>
                  <td className="px-2 py-2.5 text-gray-800 font-medium whitespace-nowrap">
                    {deal.name}
                  </td>
                  <td className="px-2 py-2.5">
                    <div className="flex items-center gap-2">
                      <Avatar name={deal.owner} color={deal.color} />
                      <span className="text-gray-600 whitespace-nowrap">{deal.owner}</span>
                    </div>
                  </td>
                  <td className="px-2 py-2.5 text-gray-600 whitespace-nowrap">{deal.amount}</td>
                  <td className="px-2 py-2.5 text-gray-600 whitespace-nowrap">{deal.close}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        <div className="flex items-center justify-center gap-1 px-6 py-3 border-t border-gray-200 bg-white shrink-0">
          <button className="p-1.5 rounded-md text-gray-400 hover:bg-gray-100">
            <ChevronLeft size={16} />
          </button>
          {[1, 2, 3, 4].map((p) => (
            <button
              key={p}
              className={`w-7 h-7 rounded-md text-[13px] ${
                p === 1
                  ? "bg-gray-900 text-white"
                  : "text-gray-500 hover:bg-gray-100"
              }`}
            >
              {p}
            </button>
          ))}
          <span className="px-1 text-gray-400">…</span>
          <button className="w-7 h-7 rounded-md text-[13px] text-gray-500 hover:bg-gray-100">
            13
          </button>
          <button className="p-1.5 rounded-md text-gray-400 hover:bg-gray-100">
            <ChevronRight size={16} />
          </button>
        </div>
      </main>

      {/* Right drawer */}
      {selected && (
        <>
          <div
            className="fixed inset-0 bg-black/10 z-20"
            onClick={() => setSelected(null)}
          />
          <aside className="w-[360px] shrink-0 bg-white border-l border-gray-200 flex flex-col z-30 relative shadow-xl">
            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
              <h2 className="text-[15px] font-semibold text-gray-900">Create deal</h2>
              <button
                onClick={() => setSelected(null)}
                className="text-gray-400 hover:text-gray-600"
              >
                <X size={18} />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto px-5 py-4">
              <Field label="Deal owner" required>
                <div className="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg">
                  <Avatar name={selected.owner} color={selected.color} />
                  <span className="text-gray-700 flex-1">{selected.owner}</span>
                  <ChevronDown size={14} className="text-gray-400" />
                </div>
              </Field>

              <Field label="Deal name" required>
                <input
                  readOnly
                  value={selected.name}
                  className="w-full px-3 py-2 border border-gray-200 rounded-lg text-gray-700"
                />
              </Field>

              <Field label="Pipeline" required>
                <div className="flex items-center justify-between px-3 py-2 border border-gray-200 rounded-lg text-gray-700">
                  Sales Pipeline
                  <ChevronDown size={14} className="text-gray-400" />
                </div>
              </Field>

              <Field label="Deal stage" required>
                <div className="flex items-center justify-between px-3 py-2 border border-gray-200 rounded-lg">
                  <span className="px-2 py-0.5 bg-rose-100 text-rose-600 rounded text-[12px] font-medium">
                    {selected.stage}
                  </span>
                  <ChevronDown size={14} className="text-gray-400" />
                </div>
              </Field>

              <Field label="Amount">
                <div className="flex items-center border border-gray-200 rounded-lg overflow-hidden">
                  <div className="flex items-center gap-1 px-3 py-2 border-r border-gray-200 text-gray-600 shrink-0">
                    USD <ChevronDown size={14} className="text-gray-400" />
                  </div>
                  <input
                    readOnly
                    value={selected.amount.replace("$", "")}
                    className="flex-1 px-3 py-2 text-gray-700 min-w-0"
                  />
                </div>
              </Field>

              <Field label="Deal type">
                <div className="flex items-center justify-between px-3 py-2 border border-gray-200 rounded-lg text-gray-700">
                  New Business
                  <ChevronDown size={14} className="text-gray-400" />
                </div>
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Start Date">
                  <div className="flex items-center justify-between px-3 py-2 border border-gray-200 rounded-lg text-gray-700">
                    10 / 03 / 2026
                    <Calendar size={14} className="text-gray-400" />
                  </div>
                </Field>
                <Field label="End Date">
                  <div className="flex items-center justify-between px-3 py-2 border border-gray-200 rounded-lg text-gray-700">
                    {selected.close.replace(/(\w+) (\d+), (\d+)/, "$2 / $1 / $3")}
                    <Calendar size={14} className="text-gray-400" />
                  </div>
                </Field>
              </div>

              <Field label="Priority">
                <div className="flex items-center justify-between px-3 py-2 border border-gray-200 rounded-lg">
                  <span className="flex items-center gap-1.5 text-blue-600">
                    <Flag size={13} /> Medium
                  </span>
                  <ChevronDown size={14} className="text-gray-400" />
                </div>
              </Field>
            </div>

            <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100">
              <button
                onClick={() => setSelected(null)}
                className="px-4 py-2 text-[13px] text-gray-600 hover:bg-gray-50 rounded-lg border border-gray-200"
              >
                Cancel
              </button>
              <button className="px-4 py-2 text-[13px] font-medium text-white bg-gray-900 rounded-lg hover:bg-gray-800">
                Create deal
              </button>
            </div>
          </aside>
        </>
      )}
    </div>
  );
}
