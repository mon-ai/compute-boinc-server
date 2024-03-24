package handlers

import (
	"fmt"
	"os"
	"os/exec"
	"time"

	"github.com/google/shlex"
	"github.com/labstack/echo/v4"
)

type boinc2docker struct {
	Cmd string `json:"cmd"`
}

func Boinc2DockerHandler(c echo.Context) error {
	// bind request body
	var req boinc2docker
	if err := c.Bind(&req); err != nil {
		return err
	}
	if req.Cmd == "" {
		return c.String(400, "cmd is required")
	}
	// lex command args
	args, err := shlex.Split(req.Cmd)
	if err != nil {
		return c.String(400, err.Error())
	}
	// create command
	cmd := exec.Command("echo", args...)
	// write stdout to file
	f, err := os.Create(fmt.Sprintf("logs/%s-stdout.log", time.Now()))
	if err != nil {
		return c.String(500, err.Error())
	}
	defer f.Close()
	cmd.Stdout = f
	// run command
	if err := cmd.Run(); err != nil {
		return c.String(500, err.Error())
	}

	return c.String(200, req.Cmd)
}
