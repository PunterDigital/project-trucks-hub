using System;
using System.Runtime.CompilerServices;

namespace TsMap.Helpers.Logger
{
    /// <summary>
    /// Stands in for ts-map's own Logger (excluded in TileExporter.csproj). That one writes every
    /// debug line to %LOCALAPPDATA%\ts-map\TsMap.log with an exclusive lock, so the parallel
    /// renderer processes crashed on start, and it left a folder in the user's profile. This one
    /// keeps the same API, writes nothing to disk and prints warnings and errors only.
    /// </summary>
    public class Logger
    {
        private static readonly Lazy<Logger> _instance = new Lazy<Logger>(() => new Logger());

        public static Logger Instance => _instance.Value;

        public void Debug(string msg, [CallerMemberName] string callerName = "", [CallerFilePath] string callerPath = "")
        {
        }

        public void Info(string msg, [CallerMemberName] string callerName = "", [CallerFilePath] string callerPath = "")
        {
        }

        public void Warning(string msg, [CallerMemberName] string callerName = "", [CallerFilePath] string callerPath = "")
        {
            Console.Error.WriteLine($"[warning] {callerName}: {msg}");
        }

        public void Error(string msg, [CallerMemberName] string callerName = "", [CallerFilePath] string callerPath = "")
        {
            Console.Error.WriteLine($"[error] {callerName}: {msg}");
        }
    }
}
